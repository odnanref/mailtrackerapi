<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\User\User;

//Request::setTrustedProxies(array('127.0.0.1'));

class TargetRepository {

    private $db = null;

    public function __construct($db) {
        $this->db = $db;
    }

    function getAll() {
        $sql = "SELECT id, name, uuid, image, datein, datelast FROM mailtracker";
        return $this->db->fetchAll($sql);
    }

    function getTargetById($id) {
        $sql = "SELECT id, name, uuid, image, datein, datelast FROM mailtracker WHERE id = ?";
        return $this->db->fetchAssoc($sql, array((int) $id));
    }

    function getTargetByUUID($uuid) {
        $sql = "SELECT id, name, uuid, image, datein, datelast FROM mailtracker WHERE uuid = ?";
        $r = $this->db->fetchAssoc($sql, array($uuid));  
        if (count($r) <= 0 || $r === null ) {
            return null;
        } else {
            return $r;
        }
    }

    function create(array $target) {
        $uuid = $this->getUUID();
        $target['datein'] = \date("Y-m-d H:i:s");
        $target['uuid'] = $uuid;
        // TODO insert to DB
        $this->db->insert('mailtracker', $target);
        return $target;
    }

    function getUUID() {
        return \date("Ymdhis") . rand(30000000, 100000000); // FIXME TEMP testing only
    }

    public function getImage( $uuid ) {

        $t = $this->getTargetByUUID($uuid);
        if ($t == null) {
            throw new \Exception("Unknown UUID " . $uuid);
        }

        $imagename = $t['image'];
        $path = './images/' . $imagename;
        if ( is_file( $path ) ) {
            return [
            'mimeinfo' => mime_content_type($path),
            'file' => $path
            ];
        } else {
            throw new \Exception("Image not found error." . $uuid . " : $path ");    
        }
        
    }
}

class TargetLog {
    private $db = null;

    public function __construct($db) {
        $this->db = $db;
    }

    public function save(array $visit) : array {
        $tr = new TargetRepository($this->db);
        $target = $tr->getTargetByUUID($visit['uuid']);
        if ($target != null) {
            $visit['mailtracker_id'] = $target['id'];
            // FIXME test
            $this->db->insert('mailtracker_log', $visit);
        }
        return $visit;
    }

    function getAllVisitsByUUID($uuid) {
        $sql = "SELECT datein, uuid, useragent, remoteip, language, charset 
        FROM mailtracker_log WHERE uuid = ?";

        return $this->db->fetchAll($sql, array($uuid));
    }

    function getAllVisitsByMailtrackerId($id) {
        $sql = "SELECT datein, uuid, useragent, remoteip, language, charset 
        FROM mailtracker_log WHERE mailtracker_id = ?";

        return $this->db->fetchAll($sql, array( (int) $id)); // FIXME this should handle big ints
    }
}


$app->get('/', function () use ($app) {
    return $app['twig']->render('index.html.twig', array());
})
    ->bind('homepage')
    ->requireHttps()
;

$app->error(function (\Exception $e, Request $request, $code) use ($app) {
    if ($app['debug']) {
        return;
    }

    // 404.html, or 40x.html, or 4xx.html, or error.html
    $templates = array(
        'errors/'.$code.'.html.twig',
        'errors/'.substr($code, 0, 2).'x.html.twig',
        'errors/'.substr($code, 0, 1).'xx.html.twig',
        'errors/default.html.twig',
    );

    return new Response($app['twig']
            ->resolveTemplate($templates)
            ->render(array('code' => $code)), $code);
});

$app->get("/logo/{uuid}", function ($uuid) use ($app) {
    // register connection
    // return 
    $tl = new TargetLog($app['db']);
    $remoteIp = array_key_exists("HTTP_X_FORWARDED_FOR", $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
    $tl->save([
        'uuid' => $uuid, 
        'useragent' => $_SERVER['HTTP_USER_AGENT'],
        'remoteip' => $remoteIp,
        'language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'],
        'charset' => array_key_exists('HTTP_ACCEPT_CHARSET', $_SERVER) ? $_SERVER['HTTP_ACCEPT_CHARSET'] : '',
        'datein' => \date("Y-m-d H:i:s")
        ]);

    $tr = new TargetRepository($app['db']);
    $res = $tr->getImage($uuid);

    $stream = function () use ($res) {
        readfile($res['file']);
    };

    if (is_array($res) && count($res) > 0 ) {
        return $app->stream( $stream, 
        200, 
        array('Content-Type' => $res['mimeinfo'])
        );
    } else {
        throw new \Exception("Unable to provide content." + $uuid);
    }
    
});

$app->post("/target/new", function (Request $request) use ($app) {
    $target = array(
        'name' => $request->request->get('name'),
        'image'  => $request->request->get('image'),
    );

    $tr = new TargetRepository($app['db']);
    $t = $tr->create($target);

    return $app->json($t, 201);
});

$app->put("/target/edit/{id}", function (Request $request,$id) use ($app){
    $target = array(
        'id' => $request->request->get('id'),
        'name' => $request->request->get('title'),
        'image'  => $request->request->get('body'),
    );

    $tr = new TargetRepository($app['db']);
    $tr->update($target);

    return $app->json($target, 201);
});

$app->get("/target/view/{id}", function ($id) use ($app) {
    $tr = new TargetRepository($app['db']);
    return $app->json($tr->getTargetById($id), 200);
});

$app->get("/target", function () use ($app) {
    $tr = new TargetRepository($app['db']);
    return $app->json($tr->getAll(), 200);
});

$app->get("/target/visits/{uuid}", function ($uuid) use ($app) {
    $tr = new TargetLog($app['db']);
    return $app->json($tr->getAllVisitsByUUID($uuid), 200);
});

$app->post("/target/media/new", function (Request $request) use ($app) {

    $app['monolog']->info("receiving file." . count($request->files));

    $upload = $request->files->get('image');
    if ($upload !== null ) {
        $upload->move("../web/images/", $upload->getClientOriginalName());  
        return $app->json( "Moved in.", 201);
    } else {
        return $app->json( "Failed.", 400);
    }
    
});

$app->get("/user-default", function() use ($app) {

    $password = $app['security.encoder.digest']->encodePassword("...");
    
    return $app->json($password, "200");
});

