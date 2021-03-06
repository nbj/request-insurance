<?php

namespace Nbj\RequestInsurance\Contracts;

interface ContainsResponseHeaders
{
    /**
     * Gets a list of all response headers
     *
     * @return array
     */
    public function getResponseHeaders();
}
