<?php

declare(strict_types=1);

namespace ThriveApi\Controllers;

use ThriveApi\Services\AccessService;

class ProductController
{
    public function __construct(private AccessService $service) {}

    public function courseMap(): array
    {
        return $this->service->buildProductCourseMap();
    }
}
