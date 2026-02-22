<?php

namespace Core\Exceptions;

class HttpResponseException extends \Exception
{
    private $data;

    public function __construct(array $data, int $code = 400)
    {
        $this->data = $data;
        parent::__construct(json_encode($data, JSON_UNESCAPED_UNICODE), $code);
    }

    public function getData(): array
    {
        return $this->data;
    }
}
