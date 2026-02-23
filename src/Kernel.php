<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        $custom = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? null;
        if (is_string($custom) && $custom !== '') {
            return $custom . '/' . $this->environment;
        }

        return $this->getProjectDir() . '/var/cache_work/' . $this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }
}
