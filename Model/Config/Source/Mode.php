<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    public const PROXY = 'proxy';
    public const CACHE = 'cache';
    public const STREAM = 'stream';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PROXY, 'label' => __('Proxy (redirect to the upstream)')],
            ['value' => self::CACHE, 'label' => __('Cache (download from the upstream on demand)')],
            ['value' => self::STREAM, 'label' => __('Stream (fetch through PHP, keep nothing on disk)')]
        ];
    }
}
