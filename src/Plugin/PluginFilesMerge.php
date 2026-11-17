<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

final class PluginFilesMerge extends PluginFilesConvert
{
    protected static $defaultName = 'plugin:files:merge';
    protected static $defaultDescription = 'Move each plugin\'s file list back into plugins.json';

    protected function toSplit(): bool
    {
        return false;
    }
}
