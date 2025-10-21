<?php

declare(strict_types=1);

namespace Nemo64\DbalRdsData\Tests\TestClasses;

use function func_get_args;

class ClassWithConstructor
{
    protected string $col1 = '';

    private string $col2 = '';

    public array $dataDuringConstruct;

    public array $dataPassedToConstructor;

    public function __construct()
    {
        $this->dataDuringConstruct = [$this->col1, $this->col2];
        $this->dataPassedToConstructor = func_get_args();
    }

    public function set(mixed $col1, mixed $col2): void
    {
        $this->col1 = $col1;
        $this->col2 = $col2;
    }
}
