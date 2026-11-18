SkeekS CMS cms-export-shop-yandex-market
===================================

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist skeeks/cms-export-shop-yandex-market "^1.1.6"
```

or add

```
"skeeks/cms-export-shop-yandex-market": "^1.1.6"
```

Configuration app
----------

```php

php yii cmsExport/execute/task 8
    
```

Links
-----
* [Web site](http://en.cms.skeeks.com)
* [Web site (rus)](http://cms.skeeks.com)
* [Author](http://skeeks.com)
* [ChangeLog](https://github.com/skeeks-cms/cms-export-shop-yandex-market/blob/master/CHANGELOG.md)

Фильтрация по остаткам
--------------------

В разделе «Фильтрация» поле «Количество больше (для всех единиц)» задаёт
общий строгий порог. Пустое поле отключает общее ограничение; 0 требует
положительного остатка. Кнопкой «+ Добавить условие для единицы» можно задать
исключения: они **заменяют**, а не дополняют общий порог.

Например: общий порог 50, шт — 5, м² — 100. Тогда штучные товары выгружаются
при остатке > 5, квадратные метры — > 100, остальные единицы — > 50.
Количество суммируется по выбранным складам; без выбора — по всем складам.
Проверяется основная единица товара (`ShopProduct.measure_code`), без пересчёта
в упаковки или минимальную партию продажи. Каждая единица допускает одно условие.
Существующие задачи без этих настроек сохраняют прежнюю выборку.

XML-текст экранируется один раз, включая бренд, артикул и характеристики.
Товар добавляется в документ только после полного формирования offer.
Отсутствие положительной выгружаемой цены или фото — явный пропуск с причиной
и предупреждением, а не ошибка XML. Для выбранного типа цены проверяется именно
он; отсутствие другой цены не исключает товар. Неожиданные сбои остаются ошибками.

Регрессионная проверка без данных сайта:

```bash
SKEEKS_APP_ROOT=/app php vendor/skeeks/cms-export-shop-yandex-market/tests/yandex-export-regression.php
```

Фид Google Merchant Center
--------------------------

Обработчик `ExportShopGoogleMerchantHandler` («[Xml] Экспорт товаров в Google
Merchant Center») наследует выгрузку Яндекса: те же выборка, склады, пороги
остатка, цены, бренды и признак выгрузки. Отличается формат: RSS 2.0 с полями
`g:*` (https://support.google.com/merchants/answer/7052112).

- `g:price` — «3153.60 RUB»; без выбранного типа цены при скидке базовая цена
  идёт в `g:price`, сниженная — в `g:sale_price`. Минимальное количество
  продажи учитывается так же, как у Яндекса.
- `g:availability` — `in_stock`/`out_of_stock` по сумме остатков выбранных
  складов (иначе всех складов товара); товар без складских записей — в наличии.
- `g:description` — текст без HTML, до 5000 символов; без описания — название.
  `g:title` — до 150 символов.
- `g:gtin` — только штрихкоды GTIN-8/12/13/14 с верной контрольной цифрой;
  `g:mpn` — `brand_sku`; без GTIN и пары бренд+артикул — `g:identifier_exists=no`.
- `g:product_type` — цепочка разделов от выгружаемого раздела; `g:condition`
  и `g:google_product_category` задаются в настройках задачи.

В Merchant Center: «Добавить товары из файла» → «Ссылка на файл», указать URL
файла задачи и расписание загрузки.

```bash
SKEEKS_APP_ROOT=/app php vendor/skeeks/cms-export-shop-yandex-market/tests/google-export-regression.php
```

При прямой выкладке обновлять также `cms-export` с методом
`ExportResult::itemSkipped()` и его адаптером `ExportJobResult`.


___

> [![skeeks!](https://skeeks.com/img/logo/logo-no-title-80px.png)](https://skeeks.com)  
<i>SkeekS CMS (Yii2) — quickly, easily and effectively!</i>  
[skeeks.com](https://skeeks.com) | [cms.skeeks.com](https://cms.skeeks.com)

