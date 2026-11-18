<?php
// No site data: in-memory fixtures and XML only.
$root = getenv('SKEEKS_APP_ROOT') ?: '/app';
require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__, 2).'/cms-export/src/ExportHandler.php';
require_once dirname(__DIR__).'/src/ExportShopYandexMarketHandler.php';
require_once dirname(__DIR__).'/src/ExportShopGoogleMerchantHandler.php';

use skeeks\cms\exportShopYandexMarket\ExportShopGoogleMerchantHandler as G;

new yii\console\Application(['id'=>'export-test', 'basePath'=>__DIR__, 'components'=>[
    'db'=>['class'=>yii\db\Connection::class, 'dsn'=>'sqlite::memory:'],
    'i18n'=>['translations'=>['skeeks/*'=>['class'=>yii\i18n\PhpMessageSource::class, 'basePath'=>__DIR__.'/messages']]],
]]);
set_exception_handler(function (Throwable $error) { fwrite(STDERR, (string)$error."\n"); exit(1); });
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
class TestHandler extends G {
    public function offer($channel, $element) { return $this->_initOffer($channel, $element); }
}
class TestElement extends skeeks\cms\shop\models\ShopCmsContentElement {
    public $fixture = [];
    public function init() {}
    public function behaviors() { return []; }
    public function __get($name) { return $this->fixture[$name] ?? null; }
}
function money($amount) {
    return new class($amount) {
        public $amount;
        public function __construct($amount) { $this->amount = $amount; }
        public function getValue() { return $this->amount; }
        public function multiply($n) { $this->amount *= $n; return $this; }
        public function getCurrency() { return new class { public function getCurrencyCode() { return 'RUB'; } }; }
    };
}
function gval(DOMElement $item, $name) {
    $nodes = $item->getElementsByTagNameNS(G::NS, $name);
    return $nodes->length ? $nodes->item(0)->textContent : null;
}

check(G::isValidGtin('4006381333931'), 'Valid EAN-13');
check(G::isValidGtin('96385074'), 'Valid EAN-8');
check(G::isValidGtin('036000291452'), 'Valid UPC-A');
check(!G::isValidGtin('4006381333932'), 'Wrong check digit rejected');
check(!G::isValidGtin('12765'), 'Internal code rejected');
check(!G::isValidGtin('0000000000000'), 'Zero code rejected');

$handler = new TestHandler(['file_path'=>'/test.xml', 'base_url'=>'https://example.com', 'is_measure_ratio_min'=>0]);
$handler->setTrees([1=>['Каталог', null], 2=>['Плитка & декор', 1], 3=>['Керамогранит', 2]]);

$product = (object)[
    'minProductPrice'=>(object)['money'=>money(3153.6)], 'baseProductPrice'=>(object)['money'=>money(3500)],
    'measure_ratio_min'=>1.44, 'brand_id'=>1, 'brand'=>(object)['name'=>'Kerama & Co'], 'brand_sku'=>'SG 1234',
    'shopProductBarcodes'=>[(object)['value'=>'4006381333931'], (object)['value'=>'12765']],
    'shopStoreProducts'=>[(object)['quantity'=>0], (object)['quantity'=>3]], 'weight'=>1500,
];
$element = new TestElement();
$element->fixture = [
    'id'=>12765, 'shopProduct'=>$product, 'tree_id'=>3,
    'mainProductImage'=>(object)['absoluteSrc'=>'https://example.com/img.jpg?a=1&b=2'],
    'images'=>[(object)['absoluteSrc'=>'https://example.com/img.jpg?a=1&b=2'], (object)['absoluteSrc'=>'https://example.com/2.jpg']],
    'productName'=>'Плитка "Мрамор" & белая', 'absoluteUrl'=>'https://example.com/item?a=1&b=2',
    'productDescriptionFull'=>'<p>Матовая&nbsp;поверхность</p><ul><li>60×60 см</li></ul><script>x</script>',
];
$handler->is_barcodes = 1;
$handler->is_second_images = 1;
$handler->is_weight = 1;

$doc = new DOMDocument('1.0', 'UTF-8');
$rss = $doc->appendChild($doc->createElement('rss'));
$rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', G::NS);
$channel = $rss->appendChild($doc->createElement('channel'));
$item = $handler->offer($channel, $element);

$parsed = new DOMDocument();
check($parsed->loadXML($doc->saveXML()), 'Feed parseable');
$item = $parsed->getElementsByTagName('item')->item(0);
check(gval($item, 'id') === '12765', 'g:id');
check(gval($item, 'title') === 'Плитка "Мрамор" & белая', 'Title escaped once');
check(gval($item, 'link') === 'https://example.com/item?a=1&b=2', 'g:link');
check(gval($item, 'image_link') === 'https://example.com/img.jpg?a=1&b=2', 'g:image_link');
check($item->getElementsByTagNameNS(G::NS, 'additional_image_link')->length === 1, 'Main image not repeated');
check(gval($item, 'price') === '3500.00 RUB', 'Base price as g:price');
check(gval($item, 'sale_price') === '3153.60 RUB', 'Discounted price as g:sale_price');
check(gval($item, 'availability') === 'in_stock', 'Stock summed over stores');
check(gval($item, 'condition') === 'new', 'Default condition new');
check(gval($item, 'brand') === 'Kerama & Co', 'g:brand');
check(gval($item, 'mpn') === 'SG 1234', 'g:mpn');
check(gval($item, 'gtin') === '4006381333931', 'Only valid GTIN exported');
check(gval($item, 'identifier_exists') === null, 'Identifiers exist');
check(gval($item, 'product_type') === 'Каталог > Плитка & декор > Керамогранит', 'Category path');
check(gval($item, 'shipping_weight') === '1500 g', 'Weight in grams');
$description = gval($item, 'description');
check(strpos($description, '<') === false && strpos($description, 'Матовая поверхность') !== false && strpos($description, '60×60 см') !== false, 'Plain text description');

// Price per minimum sale quantity, out of stock, no identifiers.
$handler->is_measure_ratio_min = 1;
$product->minProductPrice = (object)['money'=>money(1000)];
$product->baseProductPrice = null;
$product->shopStoreProducts = [(object)['quantity'=>0]];
$product->shopProductBarcodes = [];
$product->brand_sku = '';
$element->fixture['productDescriptionFull'] = null;
$item = $handler->offer($channel, $element);
check(gval($item, 'price') === '1440.00 RUB' && gval($item, 'sale_price') === null, 'Minimum sale quantity price');
check(gval($item, 'availability') === 'out_of_stock', 'Zero stock is out_of_stock');
check(gval($item, 'identifier_exists') === 'no', 'No GTIN and MPN marks identifier_exists=no');
check(gval($item, 'description') === 'Плитка "Мрамор" & белая', 'Empty description falls back to title');

$product->shopStoreProducts = [];
$element->fixture['raw_row'] = ['export_stock_quantity'=>'5'];
$item = $handler->offer($channel, $element);
check(gval($item, 'availability') === 'in_stock', 'Selected stores stock used');

$count = $channel->getElementsByTagName('item')->length;
$element->fixture['mainProductImage'] = null;
try { $handler->offer($channel, $element); check(false, 'Expected failure'); } catch (yii\base\Exception $e) {}
check($channel->getElementsByTagName('item')->length === $count, 'Failed item leaves no partial XML');

echo "OK: $checks checks\n";
