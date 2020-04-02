<?php

require(__DIR__.'/../vendor/autoload.php');

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Goutte\Client;

$app = new Silex\Application();
$app['debug'] = true;

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/app',
));

// database
$dbopts = parse_url(getenv('DATABASE_URL'));
$app->register(new Csanquer\Silex\PdoServiceProvider\Provider\PDOServiceProvider('pdo'),
               array(
                'pdo.server' => array(
                   'driver'   => 'pgsql',
                   'user' => $dbopts["user"],
                   'password' => $dbopts["pass"],
                   'host' => $dbopts["host"],
                   'port' => $dbopts["port"],
                   'dbname' => ltrim($dbopts["path"],'/')
                   )
               )
);

$app->get('/', function() use($app) {
  return $app['twig']->render('index.twig');
});

$app->get('/error', function() use($app) {
  return $app['twig']->render('error.twig');
});

$app->get('/list', function() use($app) {

  $st = $app['pdo']->prepare("SELECT 
                                    id, 
                                    name , 
                                    CONCAT('Rp. ',to_char( cast( price as numeric), 'FM9,999,999,999')) as price,
                                    CONCAT(substring(description for 90),'...') as description,
                                    gallery,
                                    to_char( last_update, 'DD-MON-YYYY') as last_update
                              FROM product_data
                            ");
  $st->execute();

  $products = array();
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
  }
  return $app['twig']->render('list.twig', array(
    'products' => $products
  ));
});

$app->post('/post', function (Request $request) use($app){
  $url = $request->get('url');
  $client = new Client();  

  try{

    $crawler = $client->request('GET', $url);
    $product_name = $crawler->filter('h1.page-title > span')->first()->text();

    $raw_data = file_get_contents($url);

    $domd = new DOMDocument();
    libxml_use_internal_errors(true);
    $domd->loadHTML($raw_data);
    libxml_use_internal_errors(false);

    $items = $domd->getElementsByTagName('script');
    $data_scr = array();

    foreach($items as $item) {
      $data_array = json_decode($item->textContent,true);
      if(is_array($data_array))
         if($data_array['[data-gallery-role=gallery-placeholder]'])
         $data_scr[] = $data_array['[data-gallery-role=gallery-placeholder]']['mage/gallery/gallery']['data'];
    };


    $collect_images = array_shift($data_scr);
    $clean_images = [];

    foreach($collect_images as $image)
    $clean_images[] = $image['full'];

    if(!$product_name)
    throw new Exception ('Invalid product url');

    $collected_data = [
      'url' => $url,
      'product_name' => $crawler->filter('h1.page-title > span')->first()->text(),
      'final_price' => $crawler->filter('.price-box.price-final_price .price-wrapper')->first()->attr('data-price-amount'),
      'description' => $crawler->filter('#description')->first()->text(),
      'images' => $clean_images,
      'main_image' => array_shift($clean_images)

    ];

    $st = $app['pdo']->prepare('INSERT INTO product_data (url,name,price,description,gallery) values(?,?,?,?,?)');
    $st->execute([$collected_data['url'],$collected_data['product_name'],$collected_data['final_price'],$collected_data['description'],json_encode($collected_data['images'])]);



  }catch(\Exception $x){
      return $app->redirect('/error');
  };

  $collected_data['final_price'] = 'Rp. ' . number_format($collected_data['final_price']);

  return $app['twig']->render('product.twig',array(
    'product_data' => $collected_data
  ));
});

$app->get('/product/{id}', function($id) use($app) {
  $st = $app['pdo']->prepare("SELECT 
                                    id, 
                                    name as product_name, 
                                    CONCAT('Rp. ',to_char( cast( price as numeric), 'FM9,999,999,999')) as final_price,
                                    description,
                                    gallery as images,
                                    url 
                              FROM product_data 
                              WHERE id = ".$id
                           );
  $st->execute();

  $result = $st->fetch(PDO::FETCH_ASSOC);

  $result['images'] = json_decode($result['images'],true);
  $result['main_image'] = array_shift($result['images']);

  return $app['twig']->render('product.twig',array(
    'product_data' => $result
  ));
});



$app->run();
