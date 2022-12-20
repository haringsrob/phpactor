<?php

namespace Phpactor\Extension\Laravel\Adapter\Laravel;

use Phpactor\WorseReflection\Core\TypeFactory;
use Phpactor\WorseReflection\Core\Type\ClassType;

class LaravelContainerInspector
{
    private array $services = [];
    public function __construct(private string $executablePath, private string $projectRoot)
    {
    }

    public function service(string $id): ?ClassType
    {
        foreach ($this->services() as $short => $service) {
            if ($short === $id || $service === $id) {
                return TypeFactory::fromString('\\' . $service);
            }
        }
        return null;
    }


    public function services(): array
    {
        if ([] === $this->services) {
            $output = shell_exec("php $this->executablePath container $this->projectRoot");
            if ($output) {
                $this->services = json_decode(trim($output), true);
            }
        }

        return $this->services;
    }
}
