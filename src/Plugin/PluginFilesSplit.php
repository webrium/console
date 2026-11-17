<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

final class PluginFilesSplit extends PluginFilesConvert
{
    protected static $defaultName = 'plugin:files:split';
    protected static $defaultDescription = 'Move each plugin\'s file list out of plugins.json into its own file';

    protected function toSplit(): bool
    {
        return true;
    }
}
