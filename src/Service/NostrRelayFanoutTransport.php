<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Multi-relay REQ fan-out: one in-process sequential {@see Request::send()} vs. one CLI worker per wss
 * ({@see bin/nostr_relay_request_worker.php}). Used for article discussion and kind-9802 highlight fetches.
 */
final readonly class NostrRelayFanoutTransport
{
    /** Extra wall time for {@see bin/nostr_relay_request_worker.php} vs. WebSocket timeout. */
    private const DISCUSSION_WORKER_GRACE_SEC = 5.0;

    /** Soft wall-time before stopping still-running parallel workers. */
    private const DISCUSSION_PARALLEL_SOFT_DEADLINE_SEC = 3.5;

    /**
     * {@see Request::send()} visits relays sequentially; cap how many wss URLs we chain in one process
     * so HTTP /fragment/comments do not hit long proxy timeouts.
     */
    private const MAX_SEQUENTIAL_RELAY_URLS = 3;

    public function __construct(
        private LoggerInterface $logger,
        private NostrRelayRequestFactory $relayRequestFactory,
        private string $projectDir,
    ) {
    }

    public function getRelayRequestTimeoutSec(): int
    {
        return $this->relayRequestFactory->getRelayRequestTimeoutSec();
    }

    /**
     * @param list<string> $relayUrls
     *
     * @return list<string>
     */
    public function capUrlsForSequential(array $relayUrls): array
    {
        if (\count($relayUrls) <= self::MAX_SEQUENTIAL_RELAY_URLS) {
            return $relayUrls;
        }
        $this->logger->notice('nostr.article_discussion.sequential_relay_cap', [
            'used' => self::MAX_SEQUENTIAL_RELAY_URLS,
            'had' => \count($relayUrls),
        ]);

        return \array_slice($relayUrls, 0, self::MAX_SEQUENTIAL_RELAY_URLS);
    }

    /**
     * One {@see Request} over all relays in the set (library visits each wss:// in series).
     *
     * @return array<string, mixed> Same shape as {@see Request::send()}
     */
    public function sendSequential(RelaySet $relaySet, RequestMessage $requestMessage, ?int $overrideTimeoutSec = null): array
    {
        $request = $this->relayRequestFactory->createTimedRequest($relaySet, $requestMessage, $overrideTimeoutSec);

        return $request->send();
    }

    /**
     * One short-lived CLI worker per relay URL (parallel WebSocket I/O).
     *
     * @param list<string> $relayUrls
     *
     * @return array<string, mixed> Same shape as {@see Request::send()}
     */
    public function sendParallelWorkers(array $relayUrls, RequestMessage $requestMessage): array
    {
        $worker = $this->projectDir.'/bin/nostr_relay_request_worker.php';
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $timeout = $this->relayRequestFactory->getRelayRequestTimeoutSec() + (int) self::DISCUSSION_WORKER_GRACE_SEC;
        $workerTimeoutEnv = ['NOSTR_RELAY_REQUEST_TIMEOUT' => (string) $this->relayRequestFactory->getRelayRequestTimeoutSec()];

        $rawPayload = serialize($requestMessage);
        $tmp = tempnam(sys_get_temp_dir(), 'nrq_');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed for Nostr discussion payload');
        }
        try {
            if (file_put_contents($tmp, $rawPayload) === false) {
                throw new \RuntimeException('Could not write Nostr discussion temp payload');
            }

            /** @var array<string, Process> $procs */
            $procs = [];
            foreach ($relayUrls as $wss) {
                if ($wss === '') {
                    continue;
                }
                $p = new Process(
                    [$phpBinary, $worker, $wss, $tmp],
                    $this->projectDir,
                    null,
                    null,
                    (float) $timeout
                );
                $p->start(null, $workerTimeoutEnv);
                $procs[$wss] = $p;
            }

            $merged = [];
            $pending = $procs;
            $deadlineAt = microtime(true) + self::DISCUSSION_PARALLEL_SOFT_DEADLINE_SEC;
            while ($pending !== []) {
                foreach ($pending as $wss => $p) {
                    if ($p->isRunning()) {
                        continue;
                    }
                    unset($pending[$wss]);
                    if (!$p->isSuccessful()) {
                        $err = $p->getErrorOutput();
                        $this->logger->warning('nostr.article_discussion.relay_worker_failed', [
                            'relay' => $wss,
                            'exit_code' => $p->getExitCode(),
                            'stderr' => $err !== '' ? $err : null,
                        ]);

                        continue;
                    }
                    $out = trim($p->getOutput());
                    if ($out === '') {
                        continue;
                    }
                    $decoded = base64_decode($out, true);
                    if ($decoded === false || $decoded === '') {
                        continue;
                    }
                    $chunk = unserialize($decoded, ['allowed_classes' => true]);
                    if (!\is_array($chunk)) {
                        continue;
                    }
                    $merged = array_replace($merged, $chunk);
                }
                if ($pending === []) {
                    break;
                }
                if (microtime(true) >= $deadlineAt) {
                    foreach ($pending as $wss => $p) {
                        $this->logger->info('nostr.article_discussion.relay_worker_soft_timeout', [
                            'relay' => $wss,
                            'soft_deadline_sec' => self::DISCUSSION_PARALLEL_SOFT_DEADLINE_SEC,
                        ]);
                        $p->stop(0.2);
                    }
                    break;
                }
                usleep(100_000);
            }

            return $merged;
        } finally {
            if (\is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * One line per relay after {@see Request::send()}: errors vs message-type counts (EVENT, EOSE, …).
     *
     * @param array<string, mixed> $response
     * @param int|null $overrideTimeoutSec when set, overrides the configured per-relay WebSocket timeout
     */
    public function logWireResponseSummary(string $context, array $response): void
    {
        foreach ($response as $relayUrl => $relayRes) {
            if ($relayRes instanceof \Throwable) {
                $this->logger->warning(sprintf(
                    'nostr.wire.relay_throwable [%s]: %s',
                    NostrRelayQuery::relayLogLabel($relayUrl),
                    $relayRes->getMessage()
                ), [
                    'context' => $context,
                    'relay' => $relayUrl,
                    'message' => $relayRes->getMessage(),
                    'class' => \get_class($relayRes),
                ]);

                continue;
            }
            if (!\is_iterable($relayRes)) {
                $this->logger->warning(sprintf(
                    'nostr.wire.relay_not_iterable [%s]: %s',
                    NostrRelayQuery::relayLogLabel($relayUrl),
                    \get_debug_type($relayRes)
                ), [
                    'context' => $context,
                    'relay' => $relayUrl,
                    'php_type' => \get_debug_type($relayRes),
                ]);

                continue;
            }
            $counts = [
                'EVENT' => 0,
                'EOSE' => 0,
                'NOTICE' => 0,
                'ERROR' => 0,
                'AUTH' => 0,
                'CLOSED' => 0,
                'other' => 0,
            ];
            foreach ($relayRes as $item) {
                if (!\is_object($item)) {
                    ++$counts['other'];

                    continue;
                }
                $t = (string) ($item->type ?? 'other');
                if (\array_key_exists($t, $counts)) {
                    ++$counts[$t];
                } else {
                    ++$counts['other'];
                }
            }
            $this->logger->info(sprintf('nostr.wire.relay_messages [%s]', NostrRelayQuery::relayLogLabel($relayUrl)), [
                'context' => $context,
                'relay' => $relayUrl,
                'counts' => $counts,
            ]);
        }
    }
}
