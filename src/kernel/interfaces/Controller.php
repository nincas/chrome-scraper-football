<?php

namespace Scraper\Kernel\Interfaces;

use Scraper\Kernel\Interfaces\Database;

interface Controller {
    public function __construct(Database $database, $param = []);
}