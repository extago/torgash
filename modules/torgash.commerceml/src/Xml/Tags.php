<?php

namespace Torgash\CommerceMl\Xml;

/**
 * CommerceML's vocabulary, which is in Russian.
 *
 * Every element and attribute in the format is a Russian word, and code that
 * writes `$node->{'ЗначенияРеквизитов'}` is code nobody can grep. So the
 * vocabulary is named ONCE, here, and the rest of the module asks for `id`,
 * `name`, `offers` — while this is the only file that knows what 1C calls them.
 *
 * It is not a translation. It is the specification's own spelling, and a typo in
 * it is a field that silently never arrives, which is why it sits alone in a
 * class of constants rather than scattered through the importers.
 */
class Tags
{
    /** @var array<string, string> */
    public const MAP = [
        // The envelope
        'commerce_information' => 'КоммерческаяИнформация',
        'schema_version' => 'ВерсияСхемы',
        'created_at' => 'ДатаФормирования',

        // The classifier: the tree, and the vocabulary of properties
        'classifier' => 'Классификатор',
        'classifier_id' => 'ИдКлассификатора',
        'groups' => 'Группы',
        'group' => 'Группа',
        'properties' => 'Свойства',
        'property' => 'Свойство',
        'property_type' => 'ТипЗначений',
        'property_variants' => 'ВариантыЗначений',
        'directory' => 'Справочник',
        'value_id' => 'ИдЗначения',

        // The catalogue
        'catalog' => 'Каталог',
        'changes_only' => 'СодержитТолькоИзменения',
        'products' => 'Товары',
        'product' => 'Товар',
        'id' => 'Ид',
        'name' => 'Наименование',
        'full_name' => 'Полное наименование',
        'article' => 'Артикул',
        'code' => 'Код',
        'barcode' => 'Штрихкод',
        'description' => 'Описание',
        'html_description' => 'ОписаниеВФорматеHTML',
        'image' => 'Картинка',
        'manufacturer' => 'Изготовитель',
        'base_unit' => 'БазоваяЕдиница',
        'status' => 'Статус',
        'deleted' => 'Удален',

        // The open bag: named attributes, and the names differ per 1C
        'attributes' => 'ЗначенияРеквизитов',
        'attribute' => 'ЗначениеРеквизита',
        'value' => 'Значение',

        // Property values ON a product
        'property_values' => 'ЗначенияСвойств',
        'property_value' => 'ЗначенияСвойства',

        // The offers package: prices, stock, warehouses
        'offer_package' => 'ПакетПредложений',
        'offers' => 'Предложения',
        'offer' => 'Предложение',
        'price_types' => 'ТипыЦен',
        'price_type' => 'ТипЦены',
        'prices' => 'Цены',
        'price' => 'Цена',
        'price_type_id' => 'ИдТипаЦены',
        'price_per_unit' => 'ЦенаЗаЕдиницу',
        'currency' => 'Валюта',
        'quantity' => 'Количество',
        'warehouses' => 'Склады',
        'warehouse' => 'Склад',
        'warehouse_id' => 'ИдСклада',
        'warehouse_quantity' => 'КоличествоНаСкладе',
        'product_features' => 'ХарактеристикиТовара',
        'product_feature' => 'ХарактеристикаТовара',

        // The unit an order line is counted in. `Код` is the OKEI code — 796 is
        // "штука", and 1C's own documents carry it, so ours do too.
        'unit_code' => 'Код',
        'unit_full_name' => 'НаименованиеПолное',
        'unit_abbreviation' => 'МеждународноеСокращение',

        // Orders
        'document' => 'Документ',
        'operation' => 'ХозОперация',
        'order_operation' => 'Заказ товара',
        'number' => 'Номер',
        'date' => 'Дата',
        'time' => 'Время',
        'total' => 'Сумма',
        'rate' => 'Курс',
        'counterparties' => 'Контрагенты',
        'counterparty' => 'Контрагент',
        'role' => 'Роль',
        'buyer' => 'Покупатель',
        'seller' => 'Продавец',
        'contacts' => 'Контакты',
        'contact' => 'Контакт',
        'address' => 'Адрес',
        'presentation' => 'Представление',
        'address_field' => 'АдресноеПоле',
        'type' => 'Тип',
        'quantity_ordered' => 'Количество',
        'notes' => 'Комментарий',
        'order_status' => 'Статус заказа',
        'payment_method' => 'Метод оплаты',
        'shipping_method' => 'Способ доставки',
        'delivery' => 'Доставка заказа',
        'service' => 'Услуга',
        'paid_at' => 'Дата оплаты по 1С',
        'shipped_at' => 'Дата отгрузки по 1С',
        'yes' => 'Да',
        'no' => 'Нет',

        // A counterparty spells its own long name WITHOUT the space a product's
        // has ("Полное наименование" vs "ПолноеНаименование"). Both are the
        // specification's, and getting them the wrong way round is a field 1C
        // quietly ignores — which is the whole argument for this file.
        'counterparty_full_name' => 'ПолноеНаименование',
        // ...and a legal entity is named officially and carries its tax number.
        'official_name' => 'ОфициальноеНаименование',
        'tax_number' => 'ИНН',
        'item_type' => 'ТипНоменклатуры',
        'paid_flag' => 'Заказ оплачен',
        'posted' => 'Проведен',
        'cancelled' => 'Отменен',
        'contact_phone' => 'ТелефонРабочий',
        'contact_email' => 'Почта',
        'discounts' => 'Скидки',
        'discount' => 'Скидка',
        'discount_included' => 'УчтеноВСумме',
    ];

    /** The 1C spelling of a name this module uses. */
    public static function of(string $key): string
    {
        return self::MAP[$key] ?? $key;
    }
}
