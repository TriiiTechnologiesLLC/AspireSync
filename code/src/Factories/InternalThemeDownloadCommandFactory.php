<?php

declare(strict_types=1);

namespace AssetGrabber\Factories;

use AssetGrabber\Commands\InternalThemeDownloadCommand;
use AssetGrabber\Services\ThemeDownloadService;
use Laminas\ServiceManager\ServiceManager;

class InternalThemeDownloadCommandFactory
{
    public function __invoke(ServiceManager $serviceManager): InternalThemeDownloadCommand
    {
        $downloadService = $serviceManager->get(ThemeDownloadService::class);
        return new InternalThemeDownloadCommand($downloadService);
    }
}
