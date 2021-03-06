<?php

use Silex\Application;
use Silex\Provider\AssetServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\HttpFragmentServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

$app = new Application();
$app->register(new ServiceControllerServiceProvider());
$app->register(new AssetServiceProvider());
$app->register(new TwigServiceProvider());
$app->register(new HttpFragmentServiceProvider());

$app['controllers']->requireHttps();

$app['twig'] = $app->extend('twig', function ($twig, $app) {
    // add custom globals, filters, tags, ...

    return $twig;
});

$app['twig.path'] = array(__DIR__.'/../templates');
$app['twig.options'] = array('cache' => __DIR__.'/../var/cache/twig');

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/../var/logs/app.log',
));

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver'   => (getenv('driver') == "" ? $netcrash['driver'] : getenv('driver')),
        'host'      => (getenv('host') == "" ? $netcrash['host'] : getenv('host')),
        'dbname'    => (getenv('dbname') == "" ? $netcrash['dbname'] : getenv('dbname')),
        'user'      => (getenv('username') == "" ? $netcrash['username'] : getenv('username')),
        'password'  => (getenv('password') == "" ? $netcrash['password'] : getenv('password')),
        'charset'   => (getenv('charset') == "" ? $netcrash['charset'] : getenv('charset'))
    ),
));

if (\trim(getenv('ADMINPASS')) == '' && \trim($netcrash['ADMINPASS']) == '') {
    throw new \Exception("No password defined.");
} else {
    $pass = (getenv("ADMINPASS") == "" ? $netcrash['ADMINPASS'] : getenv("ADMINPASS"));
}

$app['security.firewalls'] = array(
    'admin' => array(
        'pattern' => '^/mailtracker/1/target',
        'http' => true,
        'users' => array(
            // raw password is foo
            'admin' => array('ROLE_ADMIN', $pass),
        ),
    ),
);

$app->register(new Silex\Provider\SecurityServiceProvider(), array(
    'security.firewalls' => $app['security.firewalls']
));

$app['security.default_encoder'] = $app['security.encoder.digest'];

$app->before(function (Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

return $app;
