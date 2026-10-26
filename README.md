<!-- SUPPORT-START -->
<p align="center">
  <b>💛 Поддержать проект</b> · <b>USDT</b> (Tether) в сети <b>TRC20</b>:<br/>
  <code>TEHHyPSeZ7t5vfVJmjtEBkqJdpfcrm1wqg</code>
</p>
<!-- SUPPORT-END -->

<div align="center">

# WooCommerce Russian Post Shipping

### Полная интеграция WooCommerce с Почтой России

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0+-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2+-red?style=for-the-badge)](LICENSE)
[![Telegram](https://img.shields.io/badge/Telegram-@by__sonicx-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/by_sonicx)

**Лучший бесплатный плагин для доставки Почтой России в WooCommerce**

[Установка](#-установка) · [Возможности](#-возможности) · [Документация](#-api-reference) · [Поддержка](https://t.me/by_sonicx)

---

</div>

## Проблема

Интеграция интернет-магазина с Почтой России — боль для владельцев бизнеса:
- Платные модули доставки стоят от 5 000 ₽
- Бесплатные решения устарели и не поддерживаются
- Ручной расчет стоимости отнимает время
- Отслеживание посылок — отдельный квест для покупателей

## Решение

**WooCommerce Russian Post** — полностью бесплатный плагин с открытым кодом для автоматизации доставки Почтой России.

---

## Возможности

<table>
<tr>
<td width="50%">

### Расчет стоимости
- Автоматический расчет через tariff.pochta.ru
- Все типы отправлений (посылки, EMS, курьер)
- Отображение сроков доставки
- Бесплатная доставка от суммы
- Наценки (фиксированные / процентные)

</td>
<td width="50%">

### API Отправки
- Создание отправлений в ЛК Почты России
- Нормализация адресов получателей
- Наложенный платеж (COD)
- Печать этикеток и накладных

</td>
</tr>
<tr>
<td width="50%">

### Отслеживание
- Виджет отслеживания для покупателей
- Email-уведомления с трек-номером
- История перемещения посылки
- Шорткод для страницы трекинга

</td>
<td width="50%">

### Админ-панель
- Удобные настройки плагина
- Тест расчета стоимости
- Управление трек-номерами
- Логирование операций

</td>
</tr>
</table>

---

## Быстрый старт

### Требования

| Компонент | Версия |
|-----------|--------|
| WordPress | 5.8+ |
| WooCommerce | 6.0+ |
| PHP | 7.4+ |
| cURL | Включен |

### Установка

#### Способ 1: Через WordPress

```
1. Скачайте ZIP-архив плагина
2. WordPress Admin → Плагины → Добавить новый → Загрузить
3. Выберите архив и установите
4. Активируйте плагин
```

#### Способ 2: Через FTP

```bash
# Загрузите папку плагина в:
/wp-content/plugins/wc-russian-post/
```

#### Способ 3: Git Clone

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/by-sonic/wc-russian-post.git
```

---

## Настройка

### Шаг 1: Основные настройки

1. Перейдите в **WooCommerce → Почта России**
2. Укажите **почтовый индекс отправителя** (6 цифр)
3. Укажите **город отправителя**
4. Выберите **тип упаковки по умолчанию**

### Шаг 2: Добавление метода доставки

```
1. WooCommerce → Настройки → Доставка
2. Выберите зону (например, "Россия")
3. Добавить способ доставки → "Почта России"
4. Настройте параметры метода
```

### Шаг 3: API интеграция (опционально)

| API | Описание | Где получить |
|-----|----------|--------------|
| Отправка | Создание отправлений | [otpravka.pochta.ru](https://otpravka.pochta.ru) |
| Отслеживание | Трекинг посылок | [gdeposylka.ru](https://gdeposylka.ru/tracking-api/api-v4) |

---

## Типы отправлений

| Код | Название | Описание |
|-----|----------|----------|
| `27030` | Посылка стандарт | Экономичная доставка |
| `47030` | Посылка 1 класс | Ускоренная доставка |
| `23030` | Бандероль | Для печатной продукции |
| `16010` | EMS | Экспресс-доставка |
| `30030` | Курьер онлайн | Доставка до двери |
| `3020` | Письмо заказное | Документы |
| `7030` | Ценное письмо | С объявленной ценностью |

---

## Виджет отслеживания

Добавьте шорткод на любую страницу:

```
[wcrp_tracking]
```

### Параметры

| Параметр | По умолчанию | Описание |
|----------|--------------|----------|
| `title` | "Отследить посылку" | Заголовок формы |
| `button_text` | "Отследить" | Текст кнопки |
| `placeholder` | "Введите трек-номер" | Placeholder поля |

### Пример

```
[wcrp_tracking title="Где моя посылка?" button_text="Найти"]
```

---

## API Reference

### Расчет стоимости

```php
$result = WCRP_Tariff_API::calculate([
    'from' => '101000',      // Индекс отправителя
    'to' => '690000',        // Индекс получателя
    'weight' => 1000,        // Вес в граммах
    'object' => '27030',     // Тип отправления
    'sumoc' => 5000,         // Объявленная ценность
]);

if (!is_wp_error($result)) {
    echo 'Стоимость: ' . $result['cost_nds'] . ' руб.';
    echo 'Срок: ' . $result['delivery_min'] . '-' . $result['delivery_max'] . ' дней';
}
```

### Создание отправления

```php
$api = new WCRP_Otpravka_API();

$result = $api->create_order([
    'order_num' => '12345',
    'recipient_name' => 'Иванов Иван Иванович',
    'recipient_phone' => '+79001234567',
    'recipient_index' => '690000',
    'recipient_city' => 'Владивосток',
    'recipient_street' => 'ул. Ленина',
    'recipient_house' => '1',
    'weight' => 1000,
    'declared_value' => 5000,
]);

if ($result['success']) {
    echo 'ID отправления: ' . $result['shipment_id'];
}
```

### Отслеживание

```php
$tracking = new WCRP_Tracking_API();
$result = $tracking->track('RA123456789RU');

if (!is_wp_error($result)) {
    echo 'Статус: ' . $result['status_description'];
    
    foreach ($result['checkpoints'] as $checkpoint) {
        echo $checkpoint['date'] . ': ' . $checkpoint['status'];
    }
}
```

---

## Для разработчиков

### Хуки

```php
// После установки трек-номера
add_action('wcrp_track_number_set', function($order_id, $track_number) {
    // Ваш код
}, 10, 2);

// После создания отправления
add_action('wcrp_shipment_created', function($order_id, $shipment_id) {
    // Ваш код
}, 10, 2);
```

### Фильтры

```php
// Модификация параметров расчета
add_filter('wcrp_tariff_params', function($params) {
    $params['pack'] = '11'; // Использовать коробку M
    return $params;
});

// Кастомная наценка
add_filter('wcrp_shipping_cost', function($cost, $package) {
    if ($package['destination']['country'] !== 'RU') {
        return $cost * 1.5;
    }
    return $cost;
}, 10, 2);
```

---

## Troubleshooting

<details>
<summary><b>Не рассчитывается стоимость доставки</b></summary>

Проверьте:
1. Указан корректный 6-значный индекс отправителя
2. Покупатель ввел свой почтовый индекс
3. API tariff.pochta.ru доступен

</details>

<details>
<summary><b>Ошибка API Отправки</b></summary>

1. Проверьте Access Token в настройках
2. Убедитесь что токен активен в ЛК otpravka.pochta.ru
3. Проверьте логи: WooCommerce → Статус → Логи → wc-russian-post

</details>

<details>
<summary><b>Отслеживание не работает</b></summary>

1. Получите API ключ на gdeposylka.ru
2. Введите ключ в настройках плагина
3. Проверьте что трек-номер корректный (13-20 символов)

</details>

<details>
<summary><b>Проблемы с производительностью</b></summary>

Увеличьте лимиты PHP:

```ini
max_execution_time = 300
memory_limit = 256M
```

Плагин кеширует результаты расчетов и трекинга.

</details>

---

## Сравнение с конкурентами

| Функция | WC Russian Post | Конкурент A | Конкурент B |
|---------|:---------------:|:-----------:|:-----------:|
| Цена | **Бесплатно** | 5 000 ₽ | 12 000 ₽ |
| Расчет стоимости | ✅ | ✅ | ✅ |
| Создание отправлений | ✅ | ❌ | ✅ |
| Отслеживание | ✅ | ❌ | ✅ |
| Виджет для покупателей | ✅ | ❌ | ❌ |
| Открытый код | ✅ | ❌ | ❌ |
| Поддержка | ✅ Telegram | Email | Email |
| Обновления | ✅ Бесплатно | Платно | Платно |

---

## Contributing

Мы открыты для контрибьютов!

```bash
# 1. Форкните репозиторий
# 2. Создайте ветку
git checkout -b feature/amazing-feature

# 3. Закоммитьте изменения
git commit -m 'Add amazing feature'

# 4. Запушьте
git push origin feature/amazing-feature

# 5. Откройте Pull Request
```

---

## Changelog

### v1.0.0 (2026-02-05)
- Первый релиз
- Расчет стоимости через tariff.pochta.ru
- Интеграция с API Отправки (otpravka.pochta.ru)
- Отслеживание через gdeposylka.ru
- WooCommerce Shipping Method
- Виджет отслеживания и шорткод
- Админ-панель настроек
- Email-уведомления с трек-номером

---

## Лицензия

Распространяется под лицензией **GPL v2 или новее**.

Это означает:
- ✅ Бесплатное использование
- ✅ Модификация кода
- ✅ Распространение
- ✅ Коммерческое использование

---

## Автор

<div align="center">

**by sonic**

[![Telegram](https://img.shields.io/badge/Telegram-@by__sonicx-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/by_sonicx)

Если плагин был полезен — поставьте ⭐ на GitHub!

---

**Made with ❤️ for Russian e-commerce**

</div>

---

**Keywords:** WooCommerce, Почта России, Russian Post, доставка, shipping, WordPress, плагин, plugin, интеграция, API, отслеживание, tracking, tariff, тариф, расчет стоимости, EMS, посылка, бандероль, курьер, отправление, трек-номер
