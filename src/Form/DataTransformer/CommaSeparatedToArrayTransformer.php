<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

class CommaSeparatedToArrayTransformer implements DataTransformerInterface
{
    /**
     * Transforms an array into a comma-separated string.
     *
     * @param array|null $array
     * @return string
     */
    public function transform($array): string
    {
        if (null === $array || [] === $array) {
            return '';
        }

        return implode(', ', $array);
    }

    /**
     * Transforms a comma-separated string into an array.
     *
     * @param string|null $string
     * @return array
     */
    public function reverseTransform($string): array
    {
        if (null === $string || '' === trim($string)) {
            return [];
        }

        // Split by commas, trim whitespace, and filter out empty values
        $items = array_filter(array_map('trim', explode(',', $string)), function ($value) {
            return $value !== '';
        });

        return $items;
    }
}
