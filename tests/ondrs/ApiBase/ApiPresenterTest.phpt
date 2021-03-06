<?php

use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/dummies/DummyPresenter.php';


class ApiPresenterTest extends Tester\TestCase
{

    /** @var  \ondrs\ApiBase\ApiPresenter */
    private $apiPresenter;


    function setUp()
    {
        $this->apiPresenter = new DummyPresenter;

        $schemaProvider = new \ondrs\ApiBase\Services\SchemaProvider(new \Nette\Caching\Storages\DevNullStorage());
        $fakeResponse = new \ondrs\ApiBase\Services\FakeResponse($schemaProvider);

        $this->apiPresenter->schemaValidatorFactory = new \ondrs\ApiBase\Services\SchemaValidatorFactory($schemaProvider);
        $this->apiPresenter->exampleResponse = new \ondrs\ApiBase\Services\ExampleResponse($fakeResponse);

        $_SERVER['CONTENT_TYPE'] = 'application/json';
    }


    function testFilterData()
    {
        $date = new DateTime('2016-01-01 12:00');

        $data = [
            'a' => 'string',
            'b' => 2,
            'c' => [
                'ca' => 'string',
                'cb' => 3,
                'cc' => [
                    'cca' => 1,
                    'ccb' => $date,
                ],
                'cd' => $date,
            ],
            'e' => $date,
        ];

        $expected = [
            'a' => 'string',
            'b' => 2,
            'c' => [
                'ca' => 'string',
                'cb' => 3,
                'cc' => [
                    'cca' => 1,
                    'ccb' => '2016-01-01T12:00:00+0100',
                ],
                'cd' => '2016-01-01T12:00:00+0100',
            ],
            'e' => '2016-01-01T12:00:00+0100',
        ];

        Assert::same($expected, $this->apiPresenter->toResponseData($data));
    }


    function testActionDefault()
    {
        $params = ['action' => 'default'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);

        $response = $this->apiPresenter->run($request);

        Assert::same(\Nette\Http\IResponse::S200_OK, $response->getResponseCode());
        Assert::same(['result' => 'ok'], $response->getPayload());
    }


    function testActionEmpty()
    {
        $params = ['action' => 'empty'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);

        $response = $this->apiPresenter->run($request);

        Assert::same(\Nette\Http\IResponse::S200_OK, $response->getResponseCode());
        Assert::type(stdClass::class, $response->getPayload());
    }


    function testActionContent()
    {
        $params = ['action' => 'content'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);

        $response = $this->apiPresenter->run($request);

        $expected = [
            'ca' => 2,
            'cb' => 'string',
        ];

        Assert::same(\Nette\Http\IResponse::S200_OK, $response->getResponseCode());
        Assert::equal($expected, $response->getPayload());
    }


    function testNonExistingAction()
    {
        Assert::exception(function () {
            $params = ['action' => 'boo'];
            $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);

            $response = $this->apiPresenter->run($request);
        }, \ondrs\ApiBase\ApiBadRequestException::class, 'Method POST is not allowed.', 405);
    }


    function testActionWithArgs()
    {
        Assert::exception(function () {
            $params = ['action' => 'withArgs'];
            $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::GET, $params);

            $response = $this->apiPresenter->run($request);
        }, \ondrs\ApiBase\ApiBadRequestException::class, "Missing parameter(s) 'a, d'.", 400);
    }


    function testActionValidSchema()
    {
        $params = ['action' => 'validSchema'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);

        $response = $this->apiPresenter->run($request);

        Assert::same(\Nette\Http\IResponse::S200_OK, $response->getResponseCode());
    }


    function testActionInvalidSchema()
    {
        Assert::exception(function () {
            $params = ['action' => 'invalidSchema'];
            $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);
            $response = $this->apiPresenter->run($request);
        }, \ondrs\ApiBase\JsonSchemaException::class, NULL, 400);
    }


    function testNoJsonContentType()
    {
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';

        $params = ['action' => 'emptyBody'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);
        $response = $this->apiPresenter->run($request);

        Assert::same(NULL, $response->getPayload()['body']);
    }


    function testJsonContentType()
    {
        $params = ['action' => 'emptyBody'];
        $request = new \Nette\Application\Request('Dummy', \Nette\Http\IRequest::POST, $params);
        $response = $this->apiPresenter->run($request);

        Assert::type(stdClass::class, $response->getPayload()['body']);
    }

}


run(new ApiPresenterTest());
