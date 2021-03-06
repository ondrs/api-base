<?php

namespace ondrs\ApiBase;

use Nette;
use Nette\Application\BadRequestException;
use Nette\Http;
use Nette\Application\Request;
use DateTime;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use ondrs\ApiBase\Services\ExampleResponse;
use ondrs\ApiBase\Services\SchemaProvider;
use ondrs\ApiBase\Services\SchemaValidatorFactory;


abstract class ApiPresenter implements Nette\Application\IPresenter
{
    const ERROR_METHOD_IS_NOT_ALLOWED = 'Method %s is not allowed.';
    const ERROR_INVALID_JSON_DATA = 'Invalid JSON data.';
    const ERROR_JSON_SCHEMA = "JSON does not validate against schema.\n%s";
    const ERROR_MISSING_PARAMETER = "Missing parameter(s) '%s'.";

    /** @var bool */
    protected $mockResponses = FALSE;

    /** @var SchemaValidatorFactory @inject */
    public $schemaValidatorFactory;

    /** @var ExampleResponse @inject */
    public $exampleResponse;


    /** @var Request */
    protected $request;

    /** @var \stdClass|NULL */
    protected $body;


    protected function startup()
    {
        // pass
    }


    /**
     * {@inheritdoc}
     */
    public function run(Request $request)
    {
        $this->request = $request;

        $this->startup();

        $action = isset($this->request->parameters['action']) ? $this->request->parameters['action'] : 'default';

        if (!$request->isMethod(Http\IRequest::GET)) {
            $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : NULL;

            if (stripos($contentType, 'json') !== FALSE) {
                $this->body = $this->parseRequestBody($this->getRequestBody());
                $this->validate(SchemaProvider::REQUEST, $action, $this->body);
            }
        }

        if ($this->mockResponses) {
            $data = $this->exampleResponse->generate($this, SchemaProvider::RESPONSE, $action);

        } else {
            $data = $this->dispatch($request, $action);

            if ($data instanceof Nette\Application\IResponse) {
                return $data;

            } else if ($data === NULL) {
                return new ApiResponse(new \stdClass(), Http\IResponse::S200_OK);
            }

            $data = $this->toResponseData($data);
        }

        $this->validate(SchemaProvider::RESPONSE, $action, $data);

        return new ApiResponse($data, Http\IResponse::S200_OK);
    }


    /**
     * @param string $what
     * @param string $action
     * @param \stdClass $data
     * @throws BadRequestException
     */
    public function validate($what, $action, $data)
    {
        $schemaFile = SchemaProvider::getSchemaFile($this, $what, $action);

        if (!file_exists($schemaFile)) {
            return;
        }

        $validator = $this->schemaValidatorFactory->create($schemaFile);

        if ($validator->isValid($data) === FALSE) {
            $errors = Json::encode($validator->getErrors(), Json::PRETTY);

            $e = new JsonSchemaException(sprintf(self::ERROR_JSON_SCHEMA, $errors), Http\IResponse::S400_BAD_REQUEST);
            $e->errors = $errors;

            throw $e;
        }
    }


    /**
     * @param $method
     * @param $args
     * @return mixed
     * @throws \Exception
     */
    public function argsToParams($method, array $args)
    {
        $rm = new \ReflectionMethod($this, $method);

        $params = [];
        $missingParams = [];

        foreach ($rm->getParameters() as $param) {
            $name = $param->getName();

            if (isset($args[$name])) {
                $params[$name] = $args[$name];
            } else if ($param->isDefaultValueAvailable()) {
                $params[$name] = $param->getDefaultValue();
            } else {
                $missingParams[] = $param->name;
            }

        }

        if ($missingParams) {
            $this->error(sprintf(self::ERROR_MISSING_PARAMETER, join(', ', $missingParams)), Http\IResponse::S400_BAD_REQUEST);
        }

        return $params;
    }


    /**
     * @param Request $request
     * @param $action
     * @return void|array|Nette\Application\IResponse
     */
    protected function dispatch(Request $request, $action)
    {
        $method = 'action' . $action;

        if (!method_exists($this, $method)) {
            $this->error(sprintf(self::ERROR_METHOD_IS_NOT_ALLOWED, strtoupper($request->method)), Http\IResponse::S405_METHOD_NOT_ALLOWED);
        }

        return call_user_func_array([$this, $method], $this->argsToParams($method, $request->parameters));
    }


    /**
     * @return string
     */
    protected function getRequestBody()
    {
        return file_get_contents('php://input');
    }


    /**
     * @param $data
     * @return array
     */
    protected function parseRequestBody($data)
    {
        try {
            return Json::decode($data);
        } catch (JsonException $e) {
            $this->error(self::ERROR_INVALID_JSON_DATA, Http\IResponse::S400_BAD_REQUEST);
            exit; // IDE shut up
        }
    }


    /**
     * @param array $data
     * @return array
     */
    public function toResponseData(array $data)
    {
        foreach ($data as $key => $value) {
            if ($value instanceof DateTime) {
                $data[$key] = $value->format(DateTime::ISO8601);

            } elseif (is_array($value)) {
                $data[$key] = $this->toResponseData($value);
            }
        }

        return $data;
    }


    /**
     * @param string|NULL $message
     * @param int $code
     * @throws ApiBadRequestException
     */
    public function error($message = NULL, $code = Http\IResponse::S404_NOT_FOUND)
    {
        throw new ApiBadRequestException($message, $code);
    }

    /**
     * @throws Nette\Application\AbortException
     */
    protected function terminate()
    {
        throw new Nette\Application\AbortException();
    }

}
