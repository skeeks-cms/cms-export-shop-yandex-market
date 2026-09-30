<?php
use yii\helpers\Html;
use yii\helpers\Json;

$id = $widget->getId();
$name = Html::getInputName($widget->model, $widget->attribute);
$renderRow = function ($index, $row = []) use ($id, $name, $measures) {
    $prefix = $name.'['.$index.']';
    $selectId = $id.'-'.$index.'-measure';
    $quantityId = $id.'-'.$index.'-quantity';
    return Html::tag('div',
        Html::tag('div', Html::label('Единица измерения', $selectId).
            Html::dropDownList($prefix.'[measure_code]', $row['measure_code'] ?? '', $measures,
                ['id' => $selectId, 'prompt' => 'Выберите единицу', 'class' => 'sx-stock-filter-control'])).
        Html::tag('div', Html::label('Количество больше', $quantityId).
            Html::input('number', $prefix.'[quantity]', $row['quantity'] ?? '',
                ['id' => $quantityId, 'min' => 0, 'step' => 'any', 'class' => 'sx-stock-filter-control'])).
        Html::button('Удалить', ['type' => 'button', 'class' => 'sx-button sx-button--secondary', 'data-sx-stock-remove' => true]),
        ['class' => 'sx-stock-filter-row']);
};
?>
<?= Html::beginTag('div', ['id' => $id, 'class' => 'sx-stock-filter']) ?>
<?= Html::hiddenInput($name, '') ?>
<div data-sx-stock-rows>
    <?php foreach (array_values($rows) as $index => $row): ?>
        <?= $renderRow($index, $row) ?>
    <?php endforeach ?>
</div>
<template data-sx-stock-template><?= $renderRow('__index__') ?></template>
<?= Html::button('+ Добавить условие для единицы', ['type' => 'button', 'class' => 'sx-button sx-button--secondary', 'data-sx-stock-add' => true]) ?>
<?= Html::endTag('div') ?>
<?php
$this->registerCss(<<<'CSS'
.sx-stock-filter-row { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; margin-bottom: 12px; }
.sx-stock-filter-row > div { flex: 1 1 180px; min-width: 0; }
.sx-stock-filter-row label { display: block; }
.sx-stock-filter-control { box-sizing: border-box; width: 100%; min-height: var(--sx-form-control-height, 42px); padding: 8px 12px; border: 1px solid var(--sx-form-control-border-color, #ced4da); border-radius: var(--sx-form-control-radius, 4px); background: var(--sx-form-control-background, #fff); color: var(--sx-form-control-color, #212529); }
.sx-stock-filter-control:focus-visible { outline: 2px solid var(--sx-backend-theme-accent, #007bff); outline-offset: 2px; }
CSS);
$rootId = Json::htmlEncode($id);
$next = count($rows);
$this->registerJs(<<<JS
(function () {
    const root = document.getElementById($rootId);
    if (!root || root.dataset.sxStockReady) return;
    root.dataset.sxStockReady = '1';
    let next = $next;
    root.addEventListener('click', function (event) {
        if (event.target.closest('[data-sx-stock-add]')) {
            const holder = root.querySelector('[data-sx-stock-rows]');
            if (holder.children.length >= 100) return;
            const html = root.querySelector('[data-sx-stock-template]').innerHTML.replace(/__index__/g, next++);
            holder.insertAdjacentHTML('beforeend', html);
            holder.lastElementChild.querySelector('select').focus();
        }
        if (event.target.closest('[data-sx-stock-remove]')) {
            const row = event.target.closest('.sx-stock-filter-row');
            const focusTarget = row.nextElementSibling || row.previousElementSibling;
            row.remove();
            (focusTarget ? focusTarget.querySelector('select') : root.querySelector('[data-sx-stock-add]')).focus();
        }
    });
})();
JS, \yii\web\View::POS_END);
