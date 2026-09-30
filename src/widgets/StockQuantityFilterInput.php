<?php
namespace skeeks\cms\exportShopYandexMarket\widgets;

use skeeks\cms\measure\models\CmsMeasure;
use yii\helpers\ArrayHelper;
use yii\widgets\InputWidget;

/** Per-unit overrides of the export's common stock threshold. */
class StockQuantityFilterInput extends InputWidget
{
    public function run()
    {
        return $this->render('stock-quantity-filter', [
            'widget' => $this,
            'rows' => is_array($this->model->{$this->attribute}) ? $this->model->{$this->attribute} : [],
            'measures' => ArrayHelper::map(CmsMeasure::find()->orderBy(['priority' => SORT_ASC, 'name' => SORT_ASC])->all(), 'code', function ($measure) {
                return $measure->name.($measure->symbol ? ' ('.$measure->symbol.')' : '');
            }),
        ]);
    }
}
