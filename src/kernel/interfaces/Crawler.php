<?php

namespace Scraper\Kernel\Interfaces;

interface Crawler {
    // Crawler 
    public function crawl(string $file):string;
}