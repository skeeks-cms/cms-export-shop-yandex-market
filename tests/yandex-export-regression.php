<?php
// No site data: SQLite fixture and in-memory XML only.
$root = getenv('SKEEKS_APP_ROOT') ?: '/app';
require $root.'/vendor/autoload.php';
require $root.'/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__, 2).'/cms-export/src/ExportHandler.php';
require_once dirname(__DIR__).'/src/ExportShopYandexMarketHandler.php';
require_once dirname(__DIR__).'/src/widgets/StockQuantityFilterInput.php';
require_once dirname(__DIR__, 2).'/cms-export/src/helpers/ExportResult.php';
require_once dirname(__DIR__, 2).'/cms-export/src/jobs/ExportJobResult.php';

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
class TestHandler extends skeeks\cms\exportShopYandexMarket\ExportShopYandexMarketHandler {
    protected function _memoryUsage() { return ''; }
    public function node($value) { return $this->xmlElement('text', $value); }
    public function filter($query) { $this->applyQuantityFilters($query); }
    public function offer($offers, $element) { return $this->_initOffer($offers, $element); }
    public function skip($element) { return $this->getOfferSkipReason($element); }
    public function measures($measures) { $this->_measuresByCode = $measures; }
}
class TestElement extends skeeks\cms\shop\models\ShopCmsContentElement {
    public $fixture = [];
    public function init() {}
    public function behaviors() { return []; }
    public function __get($name) { return $this->fixture[$name] ?? null; }
}
Yii::$app->db->createCommand('CREATE TABLE cms_measure (code TEXT)')->execute();
Yii::$app->db->createCommand()->batchInsert('cms_measure', ['code'], [['055'],['796'],['006']])->execute();
Yii::$app->db->createCommand('CREATE TABLE products (id INTEGER, measure_code TEXT)')->execute();
Yii::$app->db->createCommand('CREATE TABLE stocks (product_id INTEGER, store_id INTEGER, quantity REAL)')->execute();
Yii::$app->db->createCommand()->batchInsert('products', ['id','measure_code'], [[1,'055'],[2,'055'],[3,'796'],[4,'796'],[5,'006'],[6,null],[7,'055']])->execute();
Yii::$app->db->createCommand()->batchInsert('stocks', ['product_id','store_id','quantity'], [[1,1,30],[1,2,21],[2,1,50],[3,1,6],[4,1,5],[5,1,51],[6,1,51],[7,3,100]])->execute();
$handler = new TestHandler(['file_path'=>'/test.xml', 'base_url'=>'https://example.com']);
$ids = function ($stores = [1,2]) use ($handler) {
    $query = new yii\db\ActiveQuery(skeeks\cms\shop\models\ShopCmsContentElement::class);
    $query->from(['shopProduct'=>'products'])->select(['shopProduct.id', 'export_stock_quantity'=>new yii\db\Expression('CAST(SUM(stock.quantity) AS REAL)')])
        ->innerJoin(['stock'=>'stocks'], 'stock.product_id = shopProduct.id')->andWhere(['stock.store_id'=>$stores])->groupBy('shopProduct.id')->orderBy('shopProduct.id');
    $handler->filter($query);
    return array_map('intval', array_column($query->createCommand()->queryAll(), 'id'));
};
check($ids() === [1,2,3,4,5,6], 'Empty thresholds retain all units');
$handler->filter_quantity_from = 50;
check($ids() === [1,5,6], 'Strict common threshold and sum across stores');
$handler->filter_quantity_by_measure = [['measure_code'=>'796','quantity'=>5]];
check($ids() === [1,3,5,6], 'Unit override replaces common threshold');
$handler->filter_quantity_by_measure[] = ['measure_code'=>'055','quantity'=>100];
check($ids() === [3,5,6], 'Common 50, pieces 5 and square metres 100 use specific priorities');
array_pop($handler->filter_quantity_by_measure);
check($ids([1]) === [3,5,6], 'Only selected stores contribute stock');
$handler->filter_quantity_from = null;
check($ids() === [1,2,3,5,6], 'Only specified unit limited without common threshold');
$handler->filter_quantity_from = 0;
check($ids() === [1,2,3,5,6], 'Zero common threshold remains enabled');
$handler->filter_quantity_by_measure = [['measure_code'=>'055','quantity'=>'50.5'], ['measure_code'=>'','quantity'=>'']];
check($handler->validate(['filter_quantity_by_measure']), 'Decimal quantity and blank row accepted');
check($handler->filter_quantity_by_measure === [['measure_code'=>'055','quantity'=>50.5]], 'Leading-zero measure code preserved');
$handler->filter_quantity_by_measure = '';
check($handler->validate(['filter_quantity_by_measure']) && $handler->filter_quantity_by_measure === [], 'Removing all rows normalizes hidden empty value');
foreach ([[['measure_code'=>'796','quantity'=>-1]], [['measure_code'=>'missing','quantity'=>1]], [['measure_code'=>'796','quantity'=>5],['measure_code'=>'796','quantity'=>7]], [['measure_code'=>'796','quantity'=>'nan']], [['measure_code'=>'796','quantity'=>[]]]] as $bad) {
    $handler->filter_quantity_by_measure = $bad;
    check(!$handler->validate(['filter_quantity_by_measure']), 'Invalid/duplicate quantity filters rejected');
}
$handler->filter_quantity_from = -1;
check(!$handler->validate(['filter_quantity_from']), 'Negative common threshold rejected');
$xml = new DOMDocument('1.0','UTF-8');
$xml->appendChild($handler->node('A & Co. <tiles> "quote" м²'));
$copy = new DOMDocument();
check($copy->loadXML($xml->saveXML()), 'XML remains parseable');
check($copy->documentElement->textContent === 'A & Co. <tiles> "quote" м²', 'Text round trip escapes exactly once');
$money = new class { public $amount = 100; public function multiply($ratio) {} public function getValue() { return 100; } public function getCurrency() { return new class { public function getCurrencyCode() { return 'RUB'; } }; } };
$product = (object)['minProductPrice'=>(object)['money'=>$money], 'baseProductPrice'=>null, 'measure_ratio_min'=>null, 'brand_id'=>1, 'brand'=>(object)['name'=>'A & Co.'], 'brand_sku'=>'SKU & PO', 'country_alpha2'=>null, 'expiration_time'=>null, 'warranty_time'=>null];
$element = new TestElement();
$element->fixture = ['id'=>42, 'shopProduct'=>$product, 'mainProductImage'=>(object)['absoluteSrc'=>'https://example.com/img?a=1&b=2'], 'productName'=>'Tile & PO', 'absoluteUrl'=>'https://example.com/item?a=1&b=2'];
$handler->is_description = 0;
$document = new DOMDocument();
$offers = $document->appendChild(new DOMElement('offers'));
$handler->offer($offers, $element);
check($offers->childNodes->length === 1, 'Offer added without base price');
check($offers->firstChild->getElementsByTagName('vendor')->item(0)->textContent === 'A & Co.', 'Brand ampersand exported');
$offer = $offers->firstChild;
check(!$offer->hasAttribute('type'), 'YML offer uses simple type');
check($offer->getElementsByTagName('name')->item(0)->textContent === 'Tile & PO', 'YML offer keeps full name');
check($offer->getElementsByTagName('model')->length === 0, 'YML offer omits duplicate model');

$parsed = new DOMDocument();
check($parsed->loadXML($document->saveXML()), 'Whole offer parseable');
check($handler->skip($element) === null, 'Valid product accepted');
$element->fixture['mainProductImage'] = null;
check($handler->skip($element) === 'У товара не задано фото.', 'Missing photo is an explicit skip');
$element->fixture['mainProductImage'] = (object)['absoluteSrc'=>'/image'];
$element->fixture['shopProduct']->minProductPrice = null;
check($handler->skip($element) === 'Нет положительной цены.', 'Missing price is an explicit skip');
$selectedProduct = new class { public $minProductPrice = null; public function getPrice($id) { return (object)['money'=>(object)['amount'=>12]]; } };
$element->fixture['shopProduct'] = $selectedProduct;
$handler->type_price_id = 2;
check($handler->skip($element) === null, 'Selected type price does not require unrelated default price');
$element->fixture['shopProduct'] = $product;
$product->minProductPrice = (object)['money'=>$money];
$handler->type_price_id = null;
$handler->is_params = 1;
$element->fixture['relatedPropertiesModel'] = new class { public function initAllProperties() { throw new RuntimeException('late failure'); } };
try { $handler->offer($offers, $element); throw new RuntimeException('Expected offer failure'); } catch (RuntimeException $e) { check($e->getMessage() === 'late failure', 'Late failure surfaced'); }
check($offers->childNodes->length === 1, 'Failed offer leaves no partial XML');
$product->measureMatches = [];
$product->measure_code = '055';
$product->country_alpha2 = 'RU';
$product->country = (object)['name'=>'Россия'];
$element->fixture['relatedPropertiesModel'] = new class { public function initAllProperties() {} public function toArray() { return []; } };
$countryOffer = $handler->offer($offers, $element);
check($countryOffer->getElementsByTagName('country_of_origin')->item(0)->textContent === 'Россия', 'country_of_origin kept');
$countryParams = [];
foreach ($countryOffer->getElementsByTagName('param') as $p) { if ($p->getAttribute('name') === 'Страна') { $countryParams[] = $p->textContent; } }
check($countryParams === ['Россия'], 'Country duplicated as param when params are exported');
$element->fixture['relatedPropertiesModel'] = new class {
    public function initAllProperties() {}
    public function toArray() { return ['country'=>'it']; }
    public function getRelatedProperty($code) { return (object)['property_type'=>'S', 'name'=>'Страна']; }
    public function getAttributeAsText($code) { return 'Италия'; }
};
$countryOffer = $handler->offer($offers, $element);
$countryParams = [];
foreach ($countryOffer->getElementsByTagName('param') as $p) { if ($p->getAttribute('name') === 'Страна') { $countryParams[] = $p->textContent; } }
check($countryParams === ['Италия'], 'Existing country property is not duplicated');
$handler->measures([
    '055'=>(object)['code'=>'055','name'=>'Квадратный метр','symbol'=>'м2'],
    '778'=>(object)['code'=>'778','name'=>'Упаковка','symbol'=>'упак'],
    '796'=>(object)['code'=>'796','name'=>'Штука','symbol'=>'шт'],
    '900'=>(object)['code'=>'900','name'=>'Палета','symbol'=>'пал'],
    '018'=>(object)['code'=>'018','name'=>'Погонный метр','symbol'=>'пог. м'],
]);
$product->measureMatches = ['778'=>1.44, '796'=>0.48, '900'=>57.6, '018'=>2, 'missing'=>3];
$matchOffer = $handler->offer($offers, $element);
$matchParams = [];
foreach ($matchOffer->getElementsByTagName('param') as $p) { $matchParams[$p->getAttribute('name')] = [$p->textContent, $p->getAttribute('unit')]; }
check(($matchParams['Площадь в упаковке'] ?? null) === ['1.44', 'кв. м'], 'Package area exported');
check(($matchParams['Площадь одной штуки'] ?? null) === ['0.48', 'кв. м'], 'Piece area exported');
check(($matchParams['Площадь в палете'] ?? null) === ['57.6', 'кв. м'], 'Pallet area exported');
check(($matchParams['Площадь в 1 пог. м'] ?? null) === ['2', 'кв. м'], 'Multi-word unit falls back to symbol');
check(count($matchParams) === 5, 'Unknown measure skipped');
$product->measure_code = '796';
$matchOffer = $handler->offer($offers, $element);
$matchParams = [];
foreach ($matchOffer->getElementsByTagName('param') as $p) { $matchParams[$p->getAttribute('name')] = [$p->textContent, $p->getAttribute('unit')]; }
check(($matchParams['Количество в упаковке'] ?? null) === ['1.44', 'шт'], 'Piece-based package uses quantity');
check(!isset($matchParams['Количество одной штуки']), 'Match equal to base measure skipped');
$product->measureMatches = [];
$product->measure_code = '055';
$handler->is_params = 0;
$countryOffer = $handler->offer($offers, $element);
check($countryOffer->getElementsByTagName('param')->length === 0, 'No country param when params are disabled');
$handler->is_description = 1;
$descriptionOf = function () use ($handler, $offers, $element) {
    $node = $handler->offer($offers, $element)->getElementsByTagName('description')->item(0);
    return $node ? $node->textContent : null;
};
$handler->is_measure_ratio_min = 0;
$product->measure_ratio_min = 1.44;
check($descriptionOf() === 'Цена указана за 1 м2', 'Missing description states price unit instead of name');
$handler->is_measure_ratio_min = 1;
check($descriptionOf() === 'Цена указана за 1,44 м2', 'Price unit follows exported minimum quantity');
$product->measure_ratio_min = null;
$product->measure_code = '796';
check($descriptionOf() === 'Цена указана за 1 шт', 'Piece price unit');
$product->measure_code = 'missing';
check($descriptionOf() === null, 'Unknown measure gives no description');
$element->fixture['productDescriptionShort'] = 'Своё описание';
check($descriptionOf() === 'Своё описание', 'Own description kept');
unset($element->fixture['productDescriptionShort']);
$product->measure_code = '055';
$handler->is_description = 0;
$reporter = new class {
    public $success=0, $skipped=0, $errors=0, $warnings=0, $processed=0;
    public function countSkipped() { $this->skipped++; }
    public function warning($message,$context) { $this->warnings++; }
    public function advance() { $this->processed++; }
    public function heartbeat() {}
    public function isCancelled() { return false; }
    public function countSuccess() { $this->success++; }
    public function itemError($type,$id,$error) { $this->errors++; }
};
$result = new skeeks\cms\export\jobs\ExportJobResult(['reporter'=>$reporter]);
$result->itemSkipped(42,'No photo'); $result->itemFinished(43); $result->itemFinished(44,'failure');
check([$reporter->skipped,$reporter->warnings,$reporter->success,$reporter->errors,$reporter->processed] === [1,1,1,1,3], 'Skipped products remain visible and counted separately');
echo "OK: $checks checks\n";
