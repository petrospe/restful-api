<?php

declare(strict_types=1);

use RestFullApi\Http\Request;
use RestFullApi\Http\Router;
use RestFullApi\Storage\JsonUserRepository;
use RestFullApi\User\UserController;

require __DIR__ . '/../src/autoload.php';

$router = new Router();
$repository = new JsonUserRepository(__DIR__ . '/../data/users.json');
$controller = new UserController($repository);
$controller->registerRoutes($router);

$response = $router->dispatch(Request::fromGlobals());
$response->send();
