<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 29.08.2016
 */
namespace skeeks\cms\exportShopYandexMarket;

use skeeks\cms\cmsWidgets\treeMenu\TreeMenuCmsWidget;
use skeeks\cms\export\ExportHandler;
use skeeks\cms\export\ExportHandlerFilePath;
use skeeks\cms\importCsv\handlers\CsvHandler;
use skeeks\cms\importCsv\helpers\CsvImportRowResult;
use skeeks\cms\importCsv\ImportCsvHandler;
use skeeks\cms\importCsvContent\widgets\MatchingInput;
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\CmsContentPropertyEnum;
use skeeks\cms\models\CmsTree;
use skeeks\cms\relatedProperties\PropertyType;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeElement;
use skeeks\cms\relatedProperties\propertyTypes\PropertyTypeList;
use skeeks\cms\shop\models\ShopCmsContentElement;
use skeeks\cms\shop\models\ShopProduct;
use skeeks\cms\widgets\formInputs\selectTree\SelectTree;
use skeeks\modules\cms\money\models\Currency;
use yii\base\Exception;
use yii\console\Application;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\helpers\Json;
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
    public $content_id = null;
    public $tree_id = null;
    public $base_url = null;

    public $file_path = '';


    public function init()
    {
        $this->name = \Yii::t('skeeks/exportShopYandexMarket', '[Xml] Simple exports of goods in yandex market');

        if (!$this->file_path)
        {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d") . "-" . \Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/yandex-market/content-{$rand}.xml";
        }

        if (!$this->base_url)
        {
            if (!\Yii::$app instanceof Application)
            {
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
        if (!$this->content_id)
        {
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

            ['content_id' , 'required'],
            ['content_id' , 'integer'],

            ['tree_id' , 'required'],
            ['tree_id' , 'integer'],

            ['base_url' , 'required'],
            ['base_url' , 'url'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'content_id'        => \Yii::t('skeeks/exportShopYandexMarket', 'Контент'),
            'tree_id'        => \Yii::t('skeeks/exportShopYandexMarket', 'Родительская категория'),
            'base_url'        => \Yii::t('skeeks/exportShopYandexMarket', 'Базовый url'),
        ]);
    }

    /**
     * @param ActiveForm $form
     */
    public function renderConfigForm(ActiveForm $form)
    {
        parent::renderConfigForm($form);

        echo $form->field($this, 'tree_id')->widget(
            SelectTree::className(),
            [
                'mode' => SelectTree::MOD_SINGLE
            ]
        );

        echo $form->field($this, 'base_url');

        echo $form->field($this, 'content_id')->listBox(
            array_merge(['' => ' - '], CmsContent::getDataForSelect(true, function(ActiveQuery $activeQuery)
            {
                $activeQuery->andWhere([
                    'id' => \yii\helpers\ArrayHelper::map(\skeeks\cms\shop\models\ShopContent::find()->all(), 'content_id', 'content_id')
                ]);
            })), [
            'size' => 1,
            'data-form-reload' => 'true'
        ]);
    }



    public function export()
    {

        //TODO: if console app
        \Yii::$app->urlManager->baseUrl = $this->base_url;
        \Yii::$app->urlManager->scriptUrl = $this->base_url;

        ini_set("memory_limit","8192M");
        set_time_limit(0);

        //Создание дирректории
        if ($dirName = dirname($this->rootFilePath))
        {
            $this->result->stdout("Создание дирректории\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName))
            {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }

        $imp = new \DOMImplementation();
		$dtd = $imp->createDocumentType('yml_catalog', '', "shops.dtd");
		$xml               = $imp->createDocument('', '', $dtd);
		$xml->encoding     = 'utf-8';
		//$xml->formatOutput = true;

        $yml_catalog = $xml->appendChild(new \DOMElement('yml_catalog'));
		$yml_catalog->appendChild(new \DOMAttr('date', date('Y-m-d H:i:s')));

            $this->result->stdout("\tДобавление основной информации\n");

        $shop = $yml_catalog->appendChild(new \DOMElement('shop'));

        $shop->appendChild(new \DOMElement('name', htmlspecialchars(\Yii::$app->name)));
		$shop->appendChild(new \DOMElement('company', htmlspecialchars(\Yii::$app->name)));
		$shop->appendChild(new \DOMElement('url', htmlspecialchars(
            $this->base_url
        )));
		$shop->appendChild(new \DOMElement('platform', "SkeekS CMS"));


        $this->_appendCurrencies($shop);
        $this->_appendCategories($shop);
        $this->_appendOffers($shop);

        $xml->formatOutput = true;
        $xml->save($this->rootFilePath);

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
        $xcurrencies = $shop->appendChild(new \DOMElement('currencies'));
		foreach (Currency::find()->active()->orderBy(['priority' => SORT_ASC])->all() as $currency)
		{
			$xcurr = $xcurrencies->appendChild(new \DOMElement('currency'));
			$xcurr->appendChild(new \DOMAttr('id', $currency->code));
			$xcurr->appendChild(new \DOMAttr('rate', (float) $currency->course));
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

        if ($rootTree)
        {
            $xcategories = $shop->appendChild(new \DOMElement('categories'));

            $trees = $rootTree->getDescendants()->orderBy(['level' => SORT_ASC])->all();
            $trees = ArrayHelper::merge([$rootTree], $trees);
            foreach ($trees as $tree)
            {
                /*$xcategories->appendChild($this->__xml->importNode($cat->toXML()->documentElement, TRUE));*/

                $xcurr = $xcategories->appendChild(new \DOMElement('category', $tree->name));
                $xcurr->appendChild(new \DOMAttr('id', $tree->id));
                if ($tree->parent && $tree->id != $rootTree->id)
                {
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
    protected function _appendOffers(\DOMElement $shop)
    {
        $elements = ShopCmsContentElement::find()->where([
            'content_id' => $this->content_id
        ])->all();

        $countTotal = count($elements);
        $this->result->stdout("\tТоваров найдено: {$countTotal}\n");


        if ($elements)
        {
            $xoffers = $shop->appendChild(new \DOMElement('offers'));
            /**
             * @var ShopCmsContentElement $element
             */
            foreach ($elements as $element)
            {
                try
                {
                    if (!$element->shopProduct || !$element->shopProduct->baseProductPrice || !$element->shopProduct->baseProductPrice->money->getValue())
                    {
                        throw new Exception("Нет данных для магазина или нулевая цена");
                        continue;
                    }


                    if ($element->shopProduct->product_type == ShopProduct::TYPE_SIMPLE)
                    {
                        $xoffer = $xoffers->appendChild(new \DOMElement('offer'));
                        $this->_initOffer($xoffer, $element);
                    } else
                    {
                        $offers = $element->tradeOffers;
                        foreach ($offers as $offer)
                        {
                            $xoffer = $xoffers->appendChild(new \DOMElement('offer'));
                            $this->_initOffer($xoffer, $offer);
                        }
                    }
                } catch (\Exception $e)
                {
                    $this->result->stdout("\t{$element->id} — {$e->getMessage()}\n", Console::FG_RED);
                    continue;
                }
            }
        }
    }

    protected function _initOffer(\DOMNode $xoffer, ShopCmsContentElement $element)
    {
        $xoffer->appendChild(new \DOMAttr('id', $element->id));

        if (!$element->shopProduct)
        {
            throw new Exception("Нет данных для магазина");
        }

        if ($element->shopProduct->quantity)
        {
            $xoffer->appendChild(new \DOMAttr('available', 'true'));
        } else
        {
            $xoffer->appendChild(new \DOMAttr('available', 'false'));
        }

        $xoffer->appendChild(new \DOMElement('url', htmlspecialchars($element->url)));
        $xoffer->appendChild(new \DOMElement('name', htmlspecialchars($element->name)));

        if ($element->image)
        {
            $xoffer->appendChild(new \DOMElement('picture', htmlspecialchars($this->base_url . $element->image->src)));
        }

        if ($element->tree_id)
        {
            $xoffer->appendChild(new \DOMElement('categoryId', $element->tree_id));
        }

        if ($element->shopProduct->baseProductPrice)
        {
            $money = $element->shopProduct->baseProductPrice->money;
            $xoffer->appendChild(new \DOMElement('price', $money->getValue()));
            $xoffer->appendChild(new \DOMElement('currencyId', $money->getCurrency()->getCurrencyCode()));
        }

        return $this;
    }
}