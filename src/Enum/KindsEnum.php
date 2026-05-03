<?php

namespace App\Enum;

enum KindsEnum: int
{
    case METADATA = 0; // metadata, NIP-01
    case DELETION_REQUEST = 5; // NIP-09
    case TEXT_NOTE = 1; // text note, NIP-01
    case REACTION = 7; // NIP-25, NIP-30 emoji in content
    case FOLLOWS = 3;
    case REPOST = 6; // Only wraps kind 1, NIP-18, will not implement
    case GENERIC_REPOST = 16; // Generic repost, original kind signalled in a "k" tag, NIP-18
    case FILE_METADATA = 1063; // NIP-94
    case COMMENTS = 1111;
    case HTTP_AUTH = 27235; // NIP-98, HTTP Auth
    case CURATION_SET = 30004; // NIP-51
    case LONGFORM = 30023; // NIP-23
    case LONGFORM_DRAFT = 30024; // NIP-23
    case PUBLICATION_INDEX = 30040;
    case ZAP = 9735; // NIP-57, Zaps
    case HIGHLIGHTS = 9802;
    case RELAY_LIST = 10002; // NIP-65, Relay list metadata
    case EMOJI_LIST = 10030; // NIP-51 standard list, NIP-30 emoji tags
    case PAYMENT_TARGETS = 10133; // NIP-A3, payto: payment targets (replaceable)
    case APP_DATA = 30078; // NIP-78, Arbitrary custom app data
    case USER_STATUS = 30315; // NIP-38, NIP-30 emoji in content
}
