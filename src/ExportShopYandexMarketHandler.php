<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 29.08.2016
 */

namespace skeeks\cms\exportShopYandexMarket;

use skeeks\cms\backend\widgets\SelectModelDialogTreeWidget;
use skeeks\cms\export\ExportHandler;
use skeeks\cms\export\ExportHandlerFilePath;
use skeeks\cms\importCsv\handlers\CsvHandler;
use skeeks\cms\importCsvContent\widgets\MatchingInput;
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentProperty;
use skeeks\cms\models\CmsTree;
use skeeks\cms\modules\admin\widgets\BlockTitleWidget;
use skeeks\cms\money\models\MoneyCurrency;
use skeeks\cms\measure\models\CmsMeasure;
use skeeks\cms\exportShopYandexMarket\widgets\StockQuantityFilterInput;
use skeeks\cms\relatedProperties\PropertyType;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use skeeks\cms\shop\models\ShopBrand;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopProduct;
use skeeks\cms\shop\models\ShopProductPrice;
use skeeks\cms\shop\models\ShopStore;
use skeeks\cms\shop\models\ShopStoreProduct;
use skeeks\cms\shop\models\ShopTypePrice;
use skeeks\cms\widgets\AjaxSelectModel;
use skeeks\modules\cms\money\models\Currency;
use skeeks\widget\chosen\Chosen;
use yii\base\Exception;
use yii\bootstrap\Alert;
use yii\console\Application;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Url;
use yii\widgets\ActiveForm;
/**
 * @property CmsContent $cmsContent
 *
 * Class CsvContentHandler
 *
 * @package skeeks\cms\importCsvContent
 */
class ExportShopYandexMarketHandler extends ExportHandler
{
    /**
     * @var null выгружаемый контент
     */
    public $content_id = null;
    /**
     * @var null раздел и его подразделы попадут в выгрузку
     */
    public $tree_id = null;
    /**
     * @var null базовый путь сайта
     */
    public $base_url = null;

    /**
     * @var null в ссылках на товары использовать этот домен
     */
    public $base_host = null;
    /**
     * @var string путь к результирующему файлу
     */
    public $file_path = '';
    /**
     * @var string
     */
    public $shop_name = '';
    /**
     * @var string
     */
    public $shop_email = '';

    /**
     * @var string
     */
    public $shop_company = '';

    /**
     * @var
     */
    public $shop_store_ids;
    /**
     * @var
     */
    public $disable_brand_ids;

    /**
     * @var Выгружаемая цена
     */
    public $type_price_id;

    /**
     * @var <repricingMin> — Минимальная возможная цена товара (МВЦ) —  данный тег нужен для обозначения вашей минимальной цены на товар. Рекомендуемая цена рассчитанная с помощью формулы репрайсинга, не может быть меньше чем МВЦ.
     */
    public $repricing_min_type_price_id;


    /**
     * @var string
     */
    public $country_of_origin = '';

    /**
     * @var string
     */
    public $vendor = '';

    /**
     * @var string
     */
    public $vendor_code = '';

    /**
     * @var string
     */
    public $default_delivery = '';
    public $default_pickup = '';
    public $default_store = '';
    public $default_sales_notes = '';

    public $is_barcodes = 0;
    public $is_weight = 0;
    public $is_description = 1;
    public $is_dimensions = 0;

    public $filter_price_from = 0;
    public $filter_price_to = 0;

    /** @var float|null Strict stock bound for units without an override. */
    public $filter_quantity_from;

    /** @var array Rows with measure_code and a strict lower stock bound. */
    public $filter_quantity_by_measure = [];

    public $is_count = 0;
    public $is_params = 0;
    public $is_second_images = 0;
    
    public $is_measure_ratio_min = 1;


    public $filter_property = '';
    public $filter_property_value = '';


    public function init()
    {
        $this->name = \Yii::t('skeeks/exportShopYandexMarket', '[Xml] Simple exports of goods in yandex market');

        if (!$this->file_path) {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d")."-".\Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/yandex-market/content-{$rand}.xml";
        }

        if (!$this->base_url) {
            if (!\Yii::$app instanceof Application) {
                $this->base_url = Url::base(true);
            }
        }

        parent::init();
    }


    /**
     * @return null|CmsContent
     */
    public function getCmsContent()
    {
        if (!$this->content_id) {
            return null;
        }

        return CmsContent::findOne($this->content_id);
    }


    /**
     * Соответствие полей
     * @var array
     */
    public $matching = [];

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [

            ['content_id', 'required'],
            ['content_id', 'integer'],

            ['tree_id', 'required'],
            ['tree_id', 'integer'],

            ['is_barcodes', 'integer'],
            ['is_dimensions', 'integer'],
            ['filter_price_from', 'number'],
            ['filter_price_to', 'number'],
            ['filter_quantity_from', 'number', 'min' => 0, 'max' => PHP_FLOAT_MAX],
            ['filter_quantity_by_measure', 'validateQuantityFilters', 'skipOnEmpty' => false],
            ['is_count', 'integer'],
            ['is_weight', 'integer'],
            ['is_description', 'integer'],
            ['is_params', 'integer'],
            ['is_measure_ratio_min', 'integer'],
            ['is_second_images', 'integer'],

            ['base_host', 'string'],

            ['base_url', 'required'],
            ['base_url', 'url'],

            ['country_of_origin', 'string'],
            ['vendor', 'string'],
            ['vendor_code', 'string'],

            ['shop_name', 'string'],
            ['shop_email', 'string'],
            ['shop_email', 'email'],
            ['shop_company', 'string'],

            ['default_sales_notes', 'string'],

            ['default_pickup', 'string'],
            ['default_store', 'string'],
            ['default_delivery', 'string'],

            ['filter_property', 'string'],
            ['filter_property_value', 'string'],

            ['type_price_id', 'integer'],
            ['repricing_min_type_price_id', 'integer'],
            ['disable_brand_ids', 'safe'],
            ['shop_store_ids', 'safe'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'content_id'        => \Yii::t('skeeks/exportShopYandexMarket', 'Контент'),
            'tree_id'           => \Yii::t('skeeks/exportShopYandexMarket', 'Выгружаемые категории'),
            'base_url'          => \Yii::t('skeeks/exportShopYandexMarket', 'Базовый url'),
            'base_host'         => \Yii::t('skeeks/exportShopYandexMarket', 'Базовый домен'),
            'is_barcodes'       => "Выгружать штрихкоды?",
            'is_weight'         => "Выгружать вес?",
            'is_description'    => "Выгружать описание?",
            'is_params'         => "Выгружать характеристики?",
            'is_measure_ratio_min'         => "Учитывать минимальное количество продажи?",
            'is_second_images'  => "Выгружать дополнительныее изображения?",
            'is_dimensions'     => "Выгружать габариты (длина, ширина, высота)?",
            'filter_price_from' => "Розничная цена (от)",
            'filter_price_to'   => "Розничная цена (до)",
            'filter_quantity_from' => 'Количество больше (для всех единиц)',
            'filter_quantity_by_measure' => 'Остаток по единицам измерения',
            'is_count'          => "Передавать количество товаров?",
            'country_of_origin' => "Страна производства товара",
            'vendor'            => \Yii::t('skeeks/exportShopYandexMarket', 'Производитель или бренд'),
            'vendor_code'       => \Yii::t('skeeks/exportShopYandexMarket', 'Артикул производителя'),
            'shop_name'         => \Yii::t('skeeks/exportShopYandexMarket', 'Короткое название магазина'),
            'shop_email'        => \Yii::t('skeeks/exportShopYandexMarket', 'Email магазина'),
            'shop_company'      => \Yii::t('skeeks/exportShopYandexMarket', 'Полное наименование компании, владеющей магазином'),

            'default_sales_notes' => \Yii::t('skeeks/exportShopYandexMarket', 'вариантах оплаты, описания акций и распродаж '),
            'default_delivery'    => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность курьерской доставки'),
            'default_pickup'      => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность самовывоза из пунктов выдачи'),
            'default_store'       => \Yii::t('skeeks/exportShopYandexMarket', 'Возможность купить товар в розничном магазине'),

            'filter_property'       => \Yii::t('skeeks/exportShopYandexMarket', 'Признак выгрузки'),
            'filter_property_value' => \Yii::t('skeeks/exportShopYandexMarket', 'Значение'),

            'shop_store_ids'    => \Yii::t('skeeks/exportShopYandexMarket', 'Склады/Поставщики/Магазины'),
            'disable_brand_ids' => \Yii::t('skeeks/exportShopYandexMarket', 'Отключить бренды'),
            'type_price_id' => \Yii::t('skeeks/exportShopYandexMarket', 'Выгружаемая цена'),
            'repricing_min_type_price_id' => \Yii::t('skeeks/exportShopYandexMarket', 'Минимальная возможная цена товара (МВЦ) '),
        ]);
    }
    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'base_host'         => "Если в ссылках нужно указать другой домен, например используется для регионов",
            'country_of_origin' => "Страна производства товара. Информацию необходимо указывать по закону. Если поле будет не заполнено, товар может быть скрыт с витрины. Список стран, которые могут быть указаны в этом элементе: http://partner.market.yandex.ru/pages/help/Countries.pdf.",
            'is_barcodes'       => "Штрикоды товара будут выгружены, толкьо если они заданы у товара",
            'is_weight'         => "Вес товара будет выгружен, только если он задан у товара",
            'is_description'    => "Описание товара будет выгружено, только если оно задано у товара",
            'filter_price_from' => "Выгружать товары только от этой цены",
            'filter_price_to'   => "Выгружать товары только до этой цены",
            'filter_quantity_from' => 'Общий порог остатка, например 50. Пустое поле — без общего ограничения. Суммируются остатки выбранных выше складов; если склады не выбраны — всех складов. Количество должно быть строго больше порога.',
            'filter_quantity_by_measure' => 'Эти условия заменяют общий порог для выбранных единиц. Например: для всех — больше 50, для шт — больше 5. Количество считается в основной единице товара, без пересчёта в упаковки.',
            'is_dimensions'     => "Габариты товара будут выгружены только если они заданы у товара",
            'is_second_images'  => "Главное изображение товара выгружается в любом случае. С этой опцией есть возможность выгрузить все фото товара.",
            'shop_name'         => \Yii::t('skeeks/exportShopYandexMarket', 'Короткое название магазина, должно содержать не более 20 символов. В названии нельзя использовать слова, не имеющие отношения к наименованию магазина, например «лучший», «дешевый», указывать номер телефона и т. п.
Название магазина должно совпадать с фактическим названием магазина, которое публикуется на сайте. При несоблюдении данного требования наименование может быть изменено Яндекс.Маркетом самостоятельно без уведомления магазина.'),
            'shop_company'      => \Yii::t('skeeks/exportShopYandexMarket', 'Полное наименование компании, владеющей магазином. Не публикуется, используется для внутренней идентификации.'),
            'default_delivery'  => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'default_pickup'    => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'default_store'     => \Yii::t('skeeks/exportShopYandexMarket', 'Для всех товаров магазина, по умолчанию'),
            'filter_property'   => "Товары у которых заполнена выбранная характеристика будут выгружены в маркет",

            'shop_store_ids'    => \Yii::t('skeeks/exportShopYandexMarket', 'Товары которые в наличии на этих складах будут добавляться в файл'),
            'disable_brand_ids' => \Yii::t('skeeks/exportShopYandexMarket', 'Выберите бренды которые не нужно выгружать в эту выгрузку.'),
            'type_price_id' => \Yii::t('skeeks/exportShopYandexMarket', 'Если не будет указана выгружаемая цена, то выгрузится розничная цена по умолчанию.'),
            'is_measure_ratio_min' => 'Если выбрано да, то цена будет выгружаться не за единицу товара, а за минимальное его количество доступное к покупке.',
            'repricing_min_type_price_id' => \Yii::t('skeeks/exportShopYandexMarket', 'repricingMin — Минимальная возможная цена товара (МВЦ) —  данный тег нужен для обозначения вашей минимальной цены на товар. Рекомендуемая цена рассчитанная с помощью формулы репрайсинга, не может быть меньше чем МВЦ.'),

            'default_sales_notes' => \Yii::t('skeeks/exportShopYandexMarket', 'Элемент используется для отражения информации о:
 минимальной сумме заказа, минимальной партии товара, необходимости предоплаты (указание элемента обязательно);
 вариантах оплаты, описания акций и распродаж (указание элемента необязательно).
Допустимая длина текста в элементе — 50 символов. .'),
        ]);
    }

    /**
     * @param ActiveForm $form
     */
    public function renderConfigForm(ActiveForm $form)
    {
        parent::renderConfigForm($form);

        echo BlockTitleWidget::widget([
            'content' => 'Общий настройки магазина',
        ]);

        echo $form->field($this, 'base_url');
        echo $form->field($this, 'base_host');
        echo $form->field($this, 'shop_name');
        echo $form->field($this, 'shop_email');
        echo $form->field($this, 'shop_company');

        echo $form->field($this, 'content_id')->listBox(
            [\Yii::$app->shop->contentProducts->id => \Yii::$app->shop->contentProducts->name], [
            'size' => 1,
            //'data-form-reload' => 'true',
        ]);

        echo $form->field($this, 'tree_id')->widget(
            SelectModelDialogTreeWidget::className(), [
                /*'treeWidgetOptions'         => [
                    'models' => CmsTree::findRoots()->cmsSite()->all(),
                ],*/
            ]
        );

        echo BlockTitleWidget::widget([
            'content' => 'Настройки данных товаров',
        ]);


        echo $form->field($this, 'default_delivery')->listBox(
            ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
            'size' => 1,
        ]);

        echo $form->field($this, 'default_pickup')->listBox(
            ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
            'size' => 1,
        ]);

        echo $form->field($this, 'default_store')->listBox(
            ArrayHelper::merge(['' => ' - '], \Yii::$app->cms->booleanFormat()), [
            'size' => 1,
        ]);

        echo $form->field($this, 'default_sales_notes')->textInput([
            'maxlength' => 50,
        ]);

        echo BlockTitleWidget::widget([
            'content' => 'Какие данные будут выгружены по товару?',
        ]);


        /*echo $form->field($this, 'vendor')->listBox(
            ArrayHelper::merge(['' => ' - '],
                ArrayHelper::map(
                    CmsContentProperty::find()->cmsSite()->andWhere([
                        'property_type' => [PropertyType::CODE_LIST, PropertyType::CODE_ELEMENT],
                    ])->all(),
                    function ($model) {
                        return "property.{$model->code}";
                    },
                    'name'
                )
            ), [
            'size' => 1,
        ]);


        echo $form->field($this, 'vendor_code')->listBox(
            ArrayHelper::merge(['' => ' - '],
                ArrayHelper::map(
                    CmsContentProperty::find()->cmsSite()->andWhere([
                        'property_type' => [PropertyType::CODE_STRING],
                    ])->all(),
                    function ($model) {
                        return "property.{$model->code}";
                    },
                    'name'
                )
            ), [
            'size' => 1,
        ]);


        echo $form->field($this, 'country_of_origin')->listBox(
            ArrayHelper::merge(['' => ' - '],
                ArrayHelper::map(
                    CmsContentProperty::find()->cmsSite()->andWhere([
                        'property_type' => [PropertyType::CODE_LIST, PropertyType::CODE_ELEMENT],
                    ])->all(),
                    function ($model) {
                        return "property.{$model->code}";
                    },
                    'name'
                )
            ), [
            'size' => 1,
        ]);*/

        echo $form->field($this, 'type_price_id')->widget(
            AjaxSelectModel::class,
            [
                'modelClass'  => ShopTypePrice::class,
                'multiple'    => false,
                'searchQuery' => function ($word = '') {
                    $query = ShopTypePrice::find();
                    if ($word) {
                        $query->search($word);
                    }
                    return $query;
                },
            ]
        );

        echo $form->field($this, 'repricing_min_type_price_id')->widget(
            AjaxSelectModel::class,
            [
                'modelClass'  => ShopTypePrice::class,
                'multiple'    => false,
                'searchQuery' => function ($word = '') {
                    $query = ShopTypePrice::find();
                    if ($word) {
                        $query->search($word);
                    }
                    return $query;
                },
            ]
        );

        echo $form->field($this, 'is_barcodes')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);

        echo $form->field($this, 'is_weight')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);
        echo $form->field($this, 'is_dimensions')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);


        echo $form->field($this, 'is_count')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);


        echo $form->field($this, 'is_description')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);

        echo $form->field($this, 'is_params')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);

        echo $form->field($this, 'is_second_images')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);

        echo $form->field($this, 'is_measure_ratio_min')->listBox(
            \Yii::$app->formatter->booleanFormat, [
            'size' => 1,
        ]);


        echo BlockTitleWidget::widget([
            'content' => 'Фильтрация',
        ]);

        echo Alert::widget([
            'options' => [
                'class' => 'alert-info',
            ],
            'body'    => 'В выгрузку попадают только активные товары и предложения. Дополнительно можно ограничить выборку опциями ниже.',
        ]);

        echo $form->field($this, 'shop_store_ids')->widget(
            Chosen::class,
            [
                'items'    => ArrayHelper::map(ShopStore::find()->cmsSite()->all(), 'id', 'asText'),
                'multiple' => true,
            ]
        );

        echo $form->field($this, 'filter_quantity_from')->input('number', ['min' => 0, 'step' => 'any']);
        echo $form->field($this, 'filter_quantity_by_measure')->widget(StockQuantityFilterInput::class);


        echo '
<div class="row">

    <div class="col-md-4 col-12">
    ';
        echo $form->field($this, 'filter_price_from')->input('number');
        echo '
    </div>
    <div class="col-md-4 col-12">
    ';
        echo $form->field($this, 'filter_price_to')->input('number');
        echo '
    </div>

</div>
';


        echo $form->field($this, 'filter_property')->listBox(
            ArrayHelper::merge(['' => ' - '], $this->getAvailableFields()), [
            'size'             => 1,
            'data-form-reload' => 'true',
        ]);

        if ($this->filter_property) {
            if ($propertyName = $this->getRelatedPropertyName($this->filter_property)) {
                $element = new CmsContentElement([
                    'content_id' => $this->cmsContent->id,
                ]);

                if ($property = $element->relatedPropertiesModel->getRelatedProperty($propertyName)) {
                    if ($property->handler instanceof PropertyTypeList) {
                        echo $form->field($this, 'filter_property_value')->listBox(
                            ArrayHelper::merge(['' => ' - '], ArrayHelper::map($property->enums, 'id', 'value')), [
                            'size' => 1,
                        ]);
                    } else {
                        echo $form->field($this, 'filter_property_value');
                    }
                } else {
                    echo $form->field($this, 'filter_property_value');
                }
            }

        }


        /*$content_id = null;
        $cmsContentProperty = \skeeks\cms\models\CmsContentProperty::find()->cmsSite()->andWhere(['is_vendor' => 1])->one();
        if ($cmsContentProperty) {
            $handler = $cmsContentProperty->handler;
            if ($handler instanceof \skeeks\cms\relatedProperties\propertyTypes\PropertyTypeElement) {
                $content_id = $handler->content_id;
            }
        }

        if ($content_id) {*/
            echo $form->field($this, 'disable_brand_ids')->widget(
                AjaxSelectModel::class,
                [
                    'modelClass'  => ShopBrand::class,
                    'multiple'    => true,
                    'searchQuery' => function ($word = '') {
                        $query = ShopBrand::find();
                        if ($word) {
                            $query->search($word);
                        }
                        return $query;
                    },
                ]
            );


        /*}*/


    }


    public function getAvailableFields()
    {
        if (!$this->cmsContent) {
            return [];
        }

        $element = new CmsContentElement([
            'content_id' => $this->cmsContent->id,
        ]);

        $fields = [];

        foreach ($element->attributeLabels() as $key => $name) {
            $fields['element.'.$key] = $name;
        }

        foreach ($element->relatedProperties as $key => $name) {
            $fields['property.'.$name->code] = $name->name." [свойство]";
        }

        $fields['image'] = 'Ссылка на главное изображение';

        return array_merge(['' => ' - '], $fields);
    }

    public function getRelatedPropertyName($fieldName)
    {
        if (strpos("field_".$fieldName, 'property.')) {
            $realName = str_replace("property.", "", $fieldName);
            return $realName;
        }
    }

    public function getElementName($fieldName)
    {
        if (strpos("field_".$fieldName, 'element.')) {
            $realName = str_replace("element.", "", $fieldName);
            return $realName;
        }

        return '';
    }

    private $_base_memory_usage = 0;

    /**
     * @return string
     */
    protected function _memoryUsage()
    {
        return \Yii::$app->formatter->asShortSize(memory_get_usage() - $this->_base_memory_usage);
    }
    
    public function export()
    {
        \Yii::$app->db->enableLogging = false;
        \Yii::$app->db->enableProfiling = false;

        //TODO: if console app
        /*\Yii::$app->urlManager->baseUrl = $this->base_url;
        \Yii::$app->urlManager->scriptUrl = $this->base_url;*/
        
        $this->_base_memory_usage = memory_get_usage();

        ini_set("memory_limit", "8192M");
        set_time_limit(0);

        //Создание дирректории
        if ($dirName = dirname($this->rootFilePath)) {
            $this->result->stdout("Создание дирректории\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName)) {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }

        $imp = new \DOMImplementation();
        $dtd = $imp->createDocumentType('yml_catalog', '', "shops.dtd");
        //$xml               = $imp->createDocument('', '', $dtd);
        $xml = $imp->createDocument('', '');
        //$xml->version     = '1.1';
        $xml->encoding = 'utf-8';
        //$xml->formatOutput = true;

        $yml_catalog = $xml->appendChild($this->xmlElement('yml_catalog'));
        $yml_catalog->appendChild(new \DOMAttr('date', \Yii::$app->formatter->asDate(time(), 'php:Y-m-d H:i:s')));

        $this->result->stdout("\tДобавление основной информации\n");

        $shop = $yml_catalog->appendChild($this->xmlElement('shop'));

        $shop->appendChild($this->xmlElement('name', $this->shop_name ?: \Yii::$app->name));
        $shop->appendChild($this->xmlElement('company', $this->shop_company ?: \Yii::$app->name));
        $shop->appendChild($this->xmlElement('email', $this->shop_email ?: \Yii::$app->cms->adminEmail));
        $shop->appendChild($this->xmlElement('url', $this->base_url));
        $shop->appendChild($this->xmlElement('platform', "SkeekS CMS"));


        $this->_appendCurrencies($shop);
        $this->_appendCategories($shop);
        
        /*$this->result->stdout("before _appendOffers [{$this->_memoryUsage()}]\n");*/

        $this->_appendOffers($shop);

        $xml->formatOutput = true;
        $this->result->checkpoint();
        // Publish only a complete file, preserving the previous feed on failure.
        $temporary = tempnam(dirname($this->rootFilePath), '.export-');
        if ($temporary === false) { throw new Exception('Не удалось создать временный файл экспорта.'); }
        try {
            if ($xml->save($temporary) === false) { throw new Exception('Не удалось записать файл экспорта.'); }
            $this->result->checkpoint();
            chmod($temporary, 0644);
            if (!rename($temporary, $this->rootFilePath)) { throw new Exception('Не удалось опубликовать файл экспорта.'); }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }

        return $this->result;
    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function _appendCurrencies(\DOMElement $shop)
    {
        $this->result->stdout("\tДобавление валют\n");
        /**
         * @var Currency $currency
         */
        $xcurrencies = $shop->appendChild($this->xmlElement('currencies'));
        foreach (MoneyCurrency::find()->andWhere(['is_active' => true])->orderBy(['priority' => SORT_ASC])->all() as $currency) {
            $xcurr = $xcurrencies->appendChild($this->xmlElement('currency'));
            $xcurr->appendChild(new \DOMAttr('id', $currency->code));
            $xcurr->appendChild(new \DOMAttr('rate', (float)$currency->course));
        }

        return $this;
    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function _appendCategories(\DOMElement $shop)
    {
        /**
         * @var CmsTree $rootTree
         * @var CmsTree $tree
         */
        $rootTree = CmsTree::findOne($this->tree_id);

        $this->result->stdout("\tВставка категорий\n");

        if ($rootTree) {
            $xcategories = $shop->appendChild($this->xmlElement('categories'));

            $trees = $rootTree->getDescendants()->orderBy(['level' => SORT_ASC])->all();
            $trees = ArrayHelper::merge([$rootTree], $trees);
            foreach ($trees as $tree) {
                /*$xcategories->appendChild($this->__xml->importNode($cat->toXML()->documentElement, TRUE));*/

                $xcurr = $xcategories->appendChild($this->xmlElement('category', $tree->name));
                $xcurr->appendChild(new \DOMAttr('id', $tree->id));
                if ($tree->parent && $tree->id != $rootTree->id) {
                    $xcurr->appendChild(new \DOMAttr('parentId', $tree->parent->id));
                }
            }
        }

    }

    /**
     * @param \DOMElement $shop
     *
     * @return $this
     */
    protected function buildOffersQuery()
    {
        $query = ShopCmsContentElement::find()
            ->active()
            ->cmsSite()
            ->innerJoinWith('shopProduct as shopProduct')
            ->joinWith('shopProduct.shopStoreProducts as shopStoreProducts')
            ->andWhere(['content_id' => $this->content_id])
            ->andWhere([
                'in',
                'shopProduct.product_type',
                [
                    ShopProduct::TYPE_SIMPLE,
                    ShopProduct::TYPE_OFFER,
                ],
            ])
            ->with([
                'shopProduct',
                'shopProduct.shopStoreProducts',
            ])
            ->groupBy(ShopCmsContentElement::tableName().".id");

        $query->select([
            ShopCmsContentElement::tableName().".*",
        ]);


        if ($this->type_price_id) {
            $defaultTypePrice = \Yii::$app->skeeks->site->getShopTypePrices()->andWhere(['id' => $this->type_price_id])->one();
        } else {
            $defaultTypePrice = \Yii::$app->skeeks->site->getShopTypePrices()->andWhere(['is_default' => 1])->one();
        }


        if ($this->filter_price_from || $this->filter_price_to) {

            if (!$defaultTypePrice) { throw new Exception('Не найден тип цены для фильтрации.'); }

            $query->leftJoin(["p{$defaultTypePrice->id}" => ShopProductPrice::tableName()], [
                "p{$defaultTypePrice->id}.product_id"    => new Expression("shopProduct.id"),
                "p{$defaultTypePrice->id}.type_price_id" => $defaultTypePrice->id,
            ]);

            $query->addSelect([
                'retail_price_filter' => new Expression("(p{$defaultTypePrice->id}.price)"),
            ]);

            if ($this->filter_price_from) {
                $query->andHaving([
                    '>=',
                    'retail_price_filter',
                    (float)$this->filter_price_from,
                ]);
            }
            if ($this->filter_price_to) {
                $query->andHaving([
                    '<=',
                    'retail_price_filter',
                    (float)$this->filter_price_to,
                ]);
            }

        }

        if ($this->shop_store_ids || $this->hasQuantityFilter() || $this->is_count) {

            $subQuery = ShopStoreProduct::find()->select(["quantity" => new Expression("sum(quantity)")])->andWhere(
                ['shop_product_id' => new Expression("shopProduct.id")],
            );
            if ($this->shop_store_ids) {
                $subQuery->andWhere(['shop_store_id' => $this->shop_store_ids]);
            }

            $query->addSelect([
                'export_stock_quantity' => $subQuery,
            ]);

        }

        if ($this->shop_store_ids) {
            $query
                ->andWhere(['in', 'shopStoreProducts.shop_store_id', $this->shop_store_ids])
                ->andWhere(['>', 'shopStoreProducts.quantity', 0]);
        }

        $this->applyQuantityFilters($query);

        if ($this->disable_brand_ids) {
            $query->andWhere(['not in', 'shopProduct.brand_id', $this->disable_brand_ids]);
        }

        /*$totalCount = $query->count();

        $this->result->stdout("\tВсего товаров: {$totalCount}\n");

        $activeTotalCount = $query->active()->count();
        $this->result->stdout("\tАктивных товаров найдено: {$activeTotalCount}\n");*/

        /**
         * Массив подразделов заданной категории
         */

        $rootTree = CmsTree::findOne($this->tree_id);
        if ($rootTree) {
            $trees = $rootTree->getDescendants()->orderBy(['level' => SORT_ASC])->all();
            $trees = ArrayHelper::merge([$rootTree], $trees);
            $query->andWhere(['tree_id' => ArrayHelper::map($trees, 'id', 'id')]);
        }
        return $query;
    }

    protected function _appendOffers(\DOMElement $shop)
    {
        $query = $this->buildOffersQuery();
        $totalCount = $query->count();
        $this->result->stdout("\tВсего товаров: {$totalCount}\n");
        $this->result->setTotal($totalCount);

        if ($totalCount) {
            $successAdded = 0;
            $xoffers = $shop->appendChild($this->xmlElement('offers'));
            /**
             * @var ShopCmsContentElement $element
             */


            foreach ($query->each(10) as $element) {
                $this->result->checkpoint();
                $itemError = null;
                try {
                    if (!$element->shopProduct) {
                        throw new Exception("Нет данных для магазина");
                        continue;
                    }

                    if ($reason = $this->getOfferSkipReason($element)) {
                        $this->result->itemSkipped($element->id, $reason);
                        continue;
                    }

                    /*if ($element->shopProduct->quantity <= 0)
                    {
                        throw new Exception("Нет в наличии");
                        continue;
                    }*/


                    $this->_initOffer($xoffers, $element);


                    $successAdded++;
                } catch (\Throwable $e) {
                    if ($e instanceof \skeeks\cms\job\exceptions\JobException) { throw $e; }
                    //echo VarDumper::dumpAsString($e, 3);

                    $this->result->stdout("\t\t{$element->id} — {$e->getMessage()}\n", Console::FG_RED);
                    $itemError = $e->getMessage();
                }
                $this->result->itemFinished($element->id, $itemError);
            }

            $this->result->stdout("\tДобавлено в файл: {$successAdded}\n");
        }
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

            if (!$attributeValue = $element->relatedPropertiesModel->getAttribute($propertyName)) {
                throw new Exception("Не найдено свойство для фильтрации");
            }

            if ($attributeValue != $this->filter_property_value) {
                throw new Exception("Не найдено свойство для фильтрации");
            }
        }


        $this->result->stdout("\t{$element->id} [{$this->_memoryUsage()}]\n");

        // Build off-document: a failed product must never leave a partial offer.
        $xoffer = $xoffers->ownerDocument->createElement('offer');
        $xoffer->appendChild(new \DOMAttr('id', $element->id));


        $xoffer->appendChild(new \DOMAttr('available', 'true'));

        /*if ($element->shopProduct->quantity)
        {
            $xoffer->appendChild(new \DOMAttr('available', 'true'));
        } else
        {
            throw new Exception("Нет в наличии");
        }*/

        $name = $element->productName;

        $url = $element->absoluteUrl;
        if ($this->base_host) {
            $urlData = parse_url($url);
            $urlData['host'] = $this->base_host;
            $url = $this->unparse_url($urlData);
        }

        /*if (!\Yii::$app->urlManager->hostInfo) {
            \Yii::$app->urlManager->hostInfo = $this->base_host;
        }*/

        $xoffer->appendChild($this->xmlElement('url', $url));

        $xoffer->appendChild($this->xmlElement('name', $name));
        $xoffer->appendChild($this->xmlElement('model', $name));

        $xoffer->appendChild($this->xmlElement('picture', $element->mainProductImage->absoluteSrc));


        if ($this->is_second_images) {
            if ($element->images) {
                foreach ($element->images as $image) {
                    $xoffer->appendChild($this->xmlElement('picture', $image->absoluteSrc));
                }
            }
        }

        if ($element->tree_id) {
            $xoffer->appendChild($this->xmlElement('categoryId', $element->tree_id));
        }

        if ($element->is_adult) {
            $xoffer->appendChild($this->xmlElement('adult', 'true'));
        }

        if ($this->type_price_id) {

            if ($price = $element->shopProduct->getPrice($this->type_price_id)) {

                $money = $price->money;

                //Если указано минимальное количество продажи и включена настройка учитывать этот параметр
                
                if ($this->is_measure_ratio_min == 1) {
                    if ($element->shopProduct->measure_ratio_min) {
                        $money->multiply($element->shopProduct->measure_ratio_min);
                    }
                }
                

                $xoffer->appendChild($this->xmlElement('price', $money->getValue()));
                $xoffer->appendChild($this->xmlElement('currencyId', $money->getCurrency()->getCurrencyCode()));
            }

        } else {
            
            if ($element->shopProduct->minProductPrice) {

                $money = $element->shopProduct->minProductPrice->money;
                $baseMoney = $element->shopProduct->baseProductPrice ? $element->shopProduct->baseProductPrice->money : null;

                //Если указано минимальное количество продажи
                if ($this->is_measure_ratio_min == 1) {
                    if ($element->shopProduct->measure_ratio_min) {
                        $money->multiply($element->shopProduct->measure_ratio_min);
                        if ($baseMoney) { $baseMoney->multiply($element->shopProduct->measure_ratio_min); }
                    }
                }
                

                $xoffer->appendChild($this->xmlElement('price', $money->getValue()));
                $xoffer->appendChild($this->xmlElement('currencyId', $money->getCurrency()->getCurrencyCode()));

                if ($baseMoney && (float)$baseMoney->amount > (float)$money->amount) {
                    $xoffer->appendChild($this->xmlElement('oldprice', $baseMoney->getValue()));
                }
            }
        }

        if ($this->repricing_min_type_price_id) {
            if ($priceRepricing = $element->shopProduct->getPrice($this->repricing_min_type_price_id)) {

                $money = $priceRepricing->money;

                //Если указано минимальное количество продажи
                if ($element->shopProduct->measure_ratio_min) {
                    $money->multiply($element->shopProduct->measure_ratio_min);
                }

                $xoffer->appendChild($this->xmlElement('repricingMin', $money->getValue()));
            }
        }


        $shopProduct = $element->shopProduct;


        //Выгружать штрихкоды?
        if ($this->is_barcodes) {
            if ($element->shopProduct->shopProductBarcodes) {
                foreach ($element->shopProduct->shopProductBarcodes as $borcode) {
                    $xoffer->appendChild($this->xmlElement('barcode', $borcode->value));
                }
            }
        }


        //Выгружать вес товара?
        if ($this->is_weight) {
            if ($shopProduct->weight) {
                $weight = $shopProduct->weight / 1000;
                $weight = round($weight, 3);

                $xoffer->appendChild($this->xmlElement('weight', $weight));
            }
        }

        //Выгружать вес товара?
        if ($this->is_description) {
            if ($element->productDescriptionFull) {
                $description = $element->productDescriptionFull;

                $xoffer->appendChild($this->xmlElement('description'))->appendChild(new \DOMCdataSection($description));
            } elseif ($element->productDescriptionShort) {
                $xoffer->appendChild($this->xmlElement('description'))->appendChild(new \DOMCdataSection($element->productDescriptionShort));
            } else {
                $xoffer->appendChild($this->xmlElement('description'))->appendChild(new \DOMCdataSection($element->productName));
            }
        }


        /**
         * @see https://yandex.ru/support/partnermarket/offers.html
         * Габариты товара (длина, ширина, высота) в упаковке. Размеры укажите в сантиметрах.
         */

        if ($this->is_dimensions) {
            if ($shopProduct->height && $shopProduct->length && $shopProduct->width) {
                $dimensions = [];
                $dimensions[] = round($shopProduct->length / 10, 3);
                $dimensions[] = round($shopProduct->width / 10, 3);
                $dimensions[] = round($shopProduct->height / 10, 3);

                $xoffer->appendChild($this->xmlElement('dimensions', implode("/", $dimensions)));
            }
        }


        if ($element->shopProduct->brand_id) {
            $xoffer->appendChild($this->xmlElement('vendor', $element->shopProduct->brand->name));
        }
        if ($element->shopProduct->brand_sku) {
            $xoffer->appendChild($this->xmlElement('vendorCode', $element->shopProduct->brand_sku));
        }
        if ($element->shopProduct->country_alpha2) {
            $xoffer->appendChild($this->xmlElement('country_of_origin', $element->shopProduct->country->name));
        }


        if ($this->default_delivery) {
            if ($this->default_delivery == 'Y') {
                $xoffer->appendChild($this->xmlElement('delivery', 'true'));
            } else if ($this->default_delivery == 'N') {
                $xoffer->appendChild($this->xmlElement('delivery', 'false'));
            }
        }

        if ($this->default_store) {
            if ($this->default_store == 'Y') {
                $xoffer->appendChild($this->xmlElement('store', 'true'));
            } else if ($this->default_store == 'N') {
                $xoffer->appendChild($this->xmlElement('store', 'false'));
            }
        }

        if ($this->default_pickup) {
            if ($this->default_pickup == 'Y') {
                $xoffer->appendChild($this->xmlElement('pickup', 'true'));
            } else if ($this->default_pickup == 'N') {
                $xoffer->appendChild($this->xmlElement('pickup', 'false'));
            }
        }

        if ($shopProduct->expiration_time) {
            $xoffer->appendChild($this->xmlElement('expiry', $this->getIso8601Time($shopProduct->expiration_time)));
        } elseif ($shopProduct->warranty_time) {
            $xoffer->appendChild($this->xmlElement('expiry', $this->getIso8601Time($shopProduct->warranty_time)));
        }

        if ($this->default_sales_notes) {
            $xoffer->appendChild($this->xmlElement('sales_notes', $this->default_sales_notes));
        }


        if ($this->is_count) {
            /**
             * @link https://yandex.ru/support/marketplace/assortment/auto/yml.html#offers
             */
            $xoffer->appendChild($this->xmlElement('count', (int)$element->raw_row['export_stock_quantity']));
        }


        if ($this->is_params) {
            $rp = $element->relatedPropertiesModel;
            $rp->initAllProperties();
            foreach ($rp->toArray() as $code => $value) {
                if ($value) {
                    $property = $rp->getRelatedProperty($code);


                    if ($property->property_type == PropertyType::CODE_NUMBER) {
                        $xParam = $this->xmlElement('param', $rp->getAttributeAsText($code));
                        $xoffer->appendChild($xParam);
                        $xParam->setAttribute('name', $property->name);
                        if ($property->cmsMeasure) { $xParam->setAttribute('unit', $property->cmsMeasure->symbol); }
                    } elseif ($property->property_type == PropertyType::CODE_LIST) {
                        if ($property->is_multiple) {
                            $data = $property->getEnums()->andWhere(['id' => $value])->select(['code', 'value'])->limit(10)->asArray()->all();
                            if ($data) {
                                foreach ($data as $key => $row) {
                                    $xParam = $this->xmlElement('param', ArrayHelper::getValue($row, 'value'));
                                    $xoffer->appendChild($xParam);
                                    $xParam->setAttribute('name', $property->name);
                                }
                            }
                        } else {
                            $xParam = $this->xmlElement('param', $rp->getAttributeAsText($code));
                            $xoffer->appendChild($xParam);
                            $xParam->setAttribute('name', $property->name);
                        }
                    } else {
                        $text = $rp->getAttributeAsText($code);
                        $text = str_replace("&ndash;", "—", $text);

                        $xParam = $this->xmlElement('param', $text);
                        $xoffer->appendChild($xParam);
                        $xParam->setAttribute('name', $property->name);
                    }
                }

            }
        }


        $xoffers->appendChild($xoffer);
        return $xoffer;
    }

    /** DOMElement parses entity references in its value, so escape raw text once. */
    protected function xmlElement($name, $value = null)
    {
        return new \DOMElement($name, htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }

    protected function getOfferSkipReason(ShopCmsContentElement $element)
    {
        $price = $this->type_price_id ? $element->shopProduct->getPrice($this->type_price_id) : $element->shopProduct->minProductPrice;
        if (!$price || (float)$price->money->amount <= 0) {
            return $this->type_price_id ? 'Нет положительной цены выбранного типа.' : 'Нет положительной цены.';
        }
        if (!$element->mainProductImage) { return 'У товара не задано фото.'; }
        return null;
    }

    public function validateQuantityFilters($attribute)
    {
        if ($this->$attribute === '' || $this->$attribute === null) { $this->$attribute = []; }
        if (!is_array($this->$attribute) || count($this->$attribute) > 100) {
            $this->addError($attribute, 'Укажите список условий (не более 100).');
            return;
        }
        if (!$this->$attribute) { return; }
        $filters = [];
        $codes = array_map('strval', CmsMeasure::find()->select('code')->column());
        foreach ($this->$attribute as $row) {
            if (!is_array($row)) { $this->addError($attribute, 'Некорректное условие остатка.'); return; }
            $code = $row['measure_code'] ?? '';
            $quantity = $row['quantity'] ?? '';
            if ($code === '' && $quantity === '') { continue; }
            if (!is_scalar($code) || !in_array((string)$code, $codes, true) ||
                !is_scalar($quantity) || !is_numeric($quantity) || !is_finite((float)$quantity) || (float)$quantity < 0) {
                $this->addError($attribute, 'Выберите единицу измерения и укажите количество не меньше нуля.');
                return;
            }
            if (isset($filters[(string)$code])) {
                $this->addError($attribute, 'Для каждой единицы измерения можно задать только одно условие.');
                return;
            }
            $filters[(string)$code] = ['measure_code' => (string)$code, 'quantity' => (float)$quantity];
        }
        $this->$attribute = array_values($filters);
    }

    protected function applyQuantityFilters(ActiveQuery $query)
    {
        if (!$this->hasQuantityFilter()) { return; }
        $query->addSelect(['export_measure_code' => new Expression('shopProduct.measure_code')]);
        $codes = array_column($this->filter_quantity_by_measure, 'measure_code');
        if (!$codes) {
            $query->andHaving(['>', 'export_stock_quantity', (float)$this->filter_quantity_from]);
            return;
        }
        $otherUnits = ['or', ['export_measure_code' => null], ['not in', 'export_measure_code', $codes]];
        $conditions = ['or'];
        $conditions[] = $this->filter_quantity_from !== null && $this->filter_quantity_from !== ''
            ? ['and', $otherUnits, ['>', 'export_stock_quantity', (float)$this->filter_quantity_from]]
            : $otherUnits;
        foreach ($this->filter_quantity_by_measure as $row) {
            $conditions[] = ['and',
                ['export_measure_code' => $row['measure_code']],
                ['>', 'export_stock_quantity', (float)$row['quantity']],
            ];
        }
        $query->andHaving($conditions);
    }

    protected function hasQuantityFilter()
    {
        return ($this->filter_quantity_from !== null && $this->filter_quantity_from !== '') || $this->filter_quantity_by_measure;
    }

    public function unparse_url($parsed_url)
    {
        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'].'://' : '';
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port = isset($parsed_url['port']) ? ':'.$parsed_url['port'] : '';
        $user = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass = isset($parsed_url['pass']) ? ':'.$parsed_url['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query = isset($parsed_url['query']) ? '?'.$parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#'.$parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * @param $hours
     * @return string
     */
    public function getIso8601Time($hours)
    {
        if ($hours >= 8640) {
            $total = round($hours / 8640);
            return "P{$total}Y0M0DT0H";
        } elseif ($hours >= 720) {
            $total = round($hours / 720);
            return "P0Y{$total}M0DT0H";
        } elseif ($hours >= 24) {
            $total = round($hours / 720);
            return "P0Y0M{$total}DT0H";
        } else {
            return "P0Y0M0DT{$hours}H";
        }
    }
}
