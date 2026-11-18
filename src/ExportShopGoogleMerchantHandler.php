<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link https://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 */

namespace skeeks\cms\exportShopYandexMarket;

use skeeks\cms\backend\widgets\SelectModelDialogTreeWidget;
use skeeks\cms\export\ExportHandler;
use skeeks\cms\models\CmsTree;
use skeeks\cms\modules\admin\widgets\BlockTitleWidget;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopTypePrice;
use skeeks\cms\widgets\AjaxSelectModel;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\widgets\ActiveForm;

/**
 * Товарный фид Google Merchant Center: RSS 2.0 с полями g:*.
 *
 * Выборка товаров, фильтры, склады и цены те же, что у фида Яндекса;
 * отличается только формат файла и правила полей Google.
 *
 * @see https://support.google.com/merchants/answer/7052112
 */
class ExportShopGoogleMerchantHandler extends ExportShopYandexMarketHandler
{
    const NS = 'http://base.google.com/ns/1.0';

    const CONDITIONS = [
        'new'         => 'Новый',
        'refurbished' => 'Восстановленный',
        'used'        => 'Б/у',
    ];

    /**
     * @var string состояние всех товаров фида (g:condition)
     */
    public $condition = 'new';

    /**
     * @var string наличие товаров без остатка: in_stock — как на странице товара (купить можно), out_of_stock — по остаткам
     */
    public $zero_stock_availability = 'in_stock';

    /**
     * @var string категория Google по умолчанию (g:google_product_category): номер или путь из таксономии Google
     */
    public $google_product_category = '';

    /**
     * @var array id раздела => [name, parent id] выгружаемого дерева, для g:product_type
     */
    private $_trees = [];

    public function init()
    {
        if (!$this->file_path) {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d")."-".\Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/google-merchant/content-{$rand}.xml";
        }

        parent::init();

        $this->name = '[Xml] Экспорт товаров в Google Merchant Center';
    }

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            ['condition', 'in', 'range' => array_keys(self::CONDITIONS)],
            ['google_product_category', 'string', 'max' => 750],
            ['zero_stock_availability', 'in', 'range' => ['in_stock', 'out_of_stock']],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'shop_name'               => 'Название магазина',
            'is_barcodes'             => 'Выгружать штрихкоды как GTIN?',
            'is_weight'               => 'Выгружать вес для доставки?',
            'is_second_images'        => 'Выгружать дополнительные изображения?',
            'condition'               => 'Состояние товаров',
            'google_product_category' => 'Категория Google',
            'zero_stock_availability' => 'Товары без остатка',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'shop_name'               => 'Заголовок фида. Если не заполнено — название сайта.',
            'is_barcodes'             => 'Выгружаются только настоящие штрихкоды производителя (EAN, UPC, ITF) с правильной контрольной цифрой. Если нет ни штрихкода, ни артикула производителя, товар помечается как не имеющий кода производителя.',
            'is_second_images'        => 'Главное фото выгружается всегда, дополнительных — не больше 10.',
            'is_measure_ratio_min'    => 'Цена в фиде должна совпадать с ценой на странице товара. Если на сайте цена указана за м² или штуку, а продаётся упаковкой, проверьте, какую цену видит покупатель.',
            'condition'               => 'Для новых товаров поле необязательно, для б/у и восстановленных — обязательно.',
            'zero_stock_availability' => 'Google сверяет наличие в фиде со страницей товара. Если такие товары на сайте можно купить (под заказ), оставьте «В наличии». «Нет в наличии» — только если на сайте их купить нельзя. Убрать товары без остатка из фида можно фильтром «Количество больше» ниже.',
            'google_product_category' => 'Необязательно. Номер категории из справочника Google, например 2047. Если пусто, Google определит категорию сам.',
        ]);
    }

    public function renderConfigForm(ActiveForm $form)
    {
        ExportHandler::renderConfigForm($form);

        echo BlockTitleWidget::widget(['content' => 'Общие настройки магазина']);

        echo $form->field($this, 'base_url');
        echo $form->field($this, 'base_host');
        echo $form->field($this, 'shop_name');

        echo $form->field($this, 'content_id')->listBox(
            [\Yii::$app->shop->contentProducts->id => \Yii::$app->shop->contentProducts->name], ['size' => 1]
        );
        echo $form->field($this, 'tree_id')->widget(SelectModelDialogTreeWidget::className());

        echo BlockTitleWidget::widget(['content' => 'Какие данные будут выгружены по товару?']);

        echo $form->field($this, 'type_price_id')->widget(AjaxSelectModel::class, [
            'modelClass'  => ShopTypePrice::class,
            'multiple'    => false,
            'searchQuery' => function ($word = '') {
                $query = ShopTypePrice::find();
                if ($word) {
                    $query->search($word);
                }
                return $query;
            },
        ]);

        echo $form->field($this, 'condition')->listBox(self::CONDITIONS, ['size' => 1]);
        echo $form->field($this, 'zero_stock_availability')->listBox([
            'in_stock'     => 'В наличии (как на странице товара)',
            'out_of_stock' => 'Нет в наличии',
        ], ['size' => 1]);
        echo $form->field($this, 'google_product_category');

        foreach (['is_measure_ratio_min', 'is_description', 'is_second_images', 'is_barcodes', 'is_weight'] as $attribute) {
            echo $form->field($this, $attribute)->listBox(\Yii::$app->formatter->booleanFormat, ['size' => 1]);
        }

        $this->renderFilterFields($form);
    }

    public function export()
    {
        \Yii::$app->db->enableLogging = false;
        \Yii::$app->db->enableProfiling = false;

        ini_set("memory_limit", "8192M");
        set_time_limit(0);

        if ($dirName = dirname($this->rootFilePath)) {
            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName)) {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $rss = $xml->appendChild($xml->createElement('rss'));
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', self::NS);

        $channel = $rss->appendChild($xml->createElement('channel'));
        $channel->appendChild($this->xmlElement('title', $this->shop_name ?: \Yii::$app->name));
        $channel->appendChild($this->xmlElement('link', $this->base_url));
        $channel->appendChild($this->xmlElement('description', $this->shop_name ?: \Yii::$app->name));

        $this->loadTrees();
        $this->_appendOffers($channel);

        // Items are built off-document, so drop their repeated xmlns:g declarations.
        $clean = new \DOMDocument('1.0', 'UTF-8');
        if (!$clean->loadXML($xml->saveXML(), LIBXML_NSCLEAN | LIBXML_PARSEHUGE)) {
            throw new Exception('Не удалось сформировать XML фида.');
        }

        $this->saveDocument($clean);

        return $this->result;
    }

    /**
     * Товары RSS-фида лежат прямо в channel.
     */
    protected function createOffersContainer(\DOMElement $shop)
    {
        return $shop;
    }

    protected function _initOffer($xoffers, ShopCmsContentElement $element)
    {
        if (!$element->shopProduct) {
            throw new Exception("Нет данных для магазина");
        }
        if (!$element->mainProductImage) {
            throw new Exception("У товара не задано фото.");
        }

        if ($this->filter_property && $this->filter_property_value) {
            $propertyName = $this->getRelatedPropertyName($this->filter_property);
            $attributeValue = $element->relatedPropertiesModel->getAttribute($propertyName);
            if (!$attributeValue || $attributeValue != $this->filter_property_value) {
                throw new Exception("Не найдено свойство для фильтрации");
            }
        }

        $shopProduct = $element->shopProduct;

        // Build off-document: a failed product must never leave a partial item.
        $item = $xoffers->ownerDocument->createElement('item');

        $this->g($item, 'id', $element->id);
        $this->g($item, 'title', $this->truncate($element->productName, 150));
        $this->g($item, 'description', $this->truncate($this->plainDescription($element), 5000));

        $url = $element->absoluteUrl;
        if ($this->base_host) {
            $urlData = parse_url($url);
            $urlData['host'] = $this->base_host;
            $url = $this->unparse_url($urlData);
        }
        $this->g($item, 'link', $url);

        $mainImage = $element->mainProductImage->absoluteSrc;
        $this->g($item, 'image_link', $mainImage);
        if ($this->is_second_images && $element->images) {
            $added = 0;
            foreach ($element->images as $image) {
                if ($added >= 10) {
                    break;
                }
                if ($image->absoluteSrc == $mainImage) {
                    continue;
                }
                $this->g($item, 'additional_image_link', $image->absoluteSrc);
                $added++;
            }
        }

        $this->g($item, 'availability', $this->getAvailability($element));

        list($price, $salePrice) = $this->getGooglePrices($element);
        if ($price === null) {
            throw new Exception("Нет цены.");
        }
        $this->g($item, 'price', $price);
        if ($salePrice !== null) {
            $this->g($item, 'sale_price', $salePrice);
        }

        if ($this->condition) {
            $this->g($item, 'condition', $this->condition);
        }

        $brand = $shopProduct->brand_id && $shopProduct->brand ? trim((string)$shopProduct->brand->name) : '';
        if ($brand !== '') {
            $this->g($item, 'brand', $this->truncate($brand, 70));
        }

        $gtins = [];
        if ($this->is_barcodes && $shopProduct->shopProductBarcodes) {
            foreach ($shopProduct->shopProductBarcodes as $barcode) {
                if (static::isValidGtin($barcode->value)) {
                    $gtins[] = trim((string)$barcode->value);
                }
            }
        }
        if ($gtins) {
            $this->g($item, 'gtin', implode(',', array_slice(array_unique($gtins), 0, 10)));
        }

        $mpn = trim((string)$shopProduct->brand_sku);
        if ($mpn !== '') {
            $this->g($item, 'mpn', $this->truncate($mpn, 70));
        }
        if (!$gtins && ($mpn === '' || $brand === '')) {
            $this->g($item, 'identifier_exists', 'no');
        }

        if ($productType = $this->getProductType($element->tree_id)) {
            $this->g($item, 'product_type', $this->truncate($productType, 750));
        }
        if ($this->google_product_category) {
            $this->g($item, 'google_product_category', $this->google_product_category);
        }

        if ($element->is_adult) {
            $this->g($item, 'adult', 'yes');
        }

        if ($this->is_weight && $shopProduct->weight) {
            $this->g($item, 'shipping_weight', round($shopProduct->weight, 3).' g');
        }

        $xoffers->appendChild($item);
        return $item;
    }

    /**
     * @param \DOMElement $item
     * @param string      $name
     * @param mixed       $value
     */
    protected function g(\DOMElement $item, $name, $value)
    {
        $node = $item->ownerDocument->createElementNS(self::NS, 'g:'.$name);
        $node->appendChild($item->ownerDocument->createTextNode((string)$value));
        $item->appendChild($node);
        return $node;
    }

    /**
     * Цена и цена со скидкой в формате Google: «3153.60 RUB».
     * @return array [price, sale_price|null]
     */
    protected function getGooglePrices(ShopCmsContentElement $element)
    {
        $shopProduct = $element->shopProduct;
        $ratio = ($this->is_measure_ratio_min == 1 && $shopProduct->measure_ratio_min) ? $shopProduct->measure_ratio_min : null;

        if ($this->type_price_id) {
            $productPrice = $shopProduct->getPrice($this->type_price_id);
            if (!$productPrice) {
                return [null, null];
            }
            $money = $productPrice->money;
            if ($ratio) {
                $money->multiply($ratio);
            }
            return [$this->formatMoney($money), null];
        }

        if (!$shopProduct->minProductPrice) {
            return [null, null];
        }
        $money = $shopProduct->minProductPrice->money;
        $baseMoney = $shopProduct->baseProductPrice ? $shopProduct->baseProductPrice->money : null;
        if ($ratio) {
            $money->multiply($ratio);
            if ($baseMoney) {
                $baseMoney->multiply($ratio);
            }
        }

        if ($baseMoney && (float)$baseMoney->amount > (float)$money->amount) {
            return [$this->formatMoney($baseMoney), $this->formatMoney($money)];
        }
        return [$this->formatMoney($money), null];
    }

    protected function formatMoney($money)
    {
        return number_format((float)$money->getValue(), 2, '.', '').' '.$money->getCurrency()->getCurrencyCode();
    }

    /**
     * Наличие по остаткам складов: выбранных в фильтре, иначе всех складов товара.
     * Товар без складских записей — в наличии; с нулевым остатком — по настройке
     * zero_stock_availability (по умолчанию «в наличии», как в разметке страницы товара).
     */
    protected function getAvailability(ShopCmsContentElement $element)
    {
        $row = (array)$element->raw_row;
        if (array_key_exists('export_stock_quantity', $row) && $row['export_stock_quantity'] !== null) {
            $quantity = (float)$row['export_stock_quantity'];
        } else {
            $storeProducts = $element->shopProduct->shopStoreProducts;
            if (!$storeProducts) {
                return 'in_stock';
            }
            $quantity = 0;
            foreach ($storeProducts as $storeProduct) {
                $quantity += (float)$storeProduct->quantity;
            }
        }

        if ($quantity > 0) {
            return 'in_stock';
        }
        return $this->zero_stock_availability === 'out_of_stock' ? 'out_of_stock' : 'in_stock';
    }

    /**
     * Описание без HTML; если его нет — название товара.
     */
    protected function plainDescription(ShopCmsContentElement $element)
    {
        $html = '';
        if ($this->is_description) {
            $html = $element->productDescriptionFull ?: $element->productDescriptionShort;
        }

        $html = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6]|\/tr)[^>]*>/iu', "\n", (string)$html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = trim(preg_replace("/\s*\n\s*/u", "\n", $text));

        return $text !== '' ? $text : (string)$element->productName;
    }

    protected function truncate($text, $length)
    {
        $text = trim((string)$text);
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length - 1, 'UTF-8')).'…';
    }

    /**
     * GTIN-8/12/13/14 с верной контрольной цифрой.
     * @param string $value
     * @return bool
     */
    public static function isValidGtin($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $value) || (int)$value === 0) {
            return false;
        }

        $digits = array_map('intval', str_split($value));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    protected function loadTrees()
    {
        $this->_trees = [];
        if (!$rootTree = CmsTree::findOne($this->tree_id)) {
            return;
        }
        $this->_trees[$rootTree->id] = [$rootTree->name, null];
        foreach ($rootTree->getDescendants()->select(['id', 'pid', 'name'])->asArray()->all() as $tree) {
            $this->_trees[$tree['id']] = [$tree['name'], $tree['pid']];
        }
    }

    /**
     * Цепочка разделов «Плитка > Керамогранит» до раздела товара, без самого
     * выгружаемого раздела («Каталог»); товар прямо в нём — его название.
     * @param int|null $treeId
     * @return string
     */
    protected function getProductType($treeId)
    {
        $names = [];
        while ($treeId && isset($this->_trees[$treeId]) && count($names) < 20) {
            array_unshift($names, $this->_trees[$treeId][0]);
            $treeId = $this->_trees[$treeId][1];
        }
        if (count($names) > 1) {
            array_shift($names);
        }
        return implode(' > ', $names);
    }

    /**
     * @param array $trees id => [name, parent id]
     * @internal for tests
     */
    public function setTrees(array $trees)
    {
        $this->_trees = $trees;
    }
}
