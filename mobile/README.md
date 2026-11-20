# Приложение HomeVlad

Один клиент для пользователя и администратора на каждой платформе. Нативные экраны показывают баланс, машины и состояние инфраструктуры. Создание машин, финансы, поддержка и административные операции открываются в кабинете с той же сессией. Административное меню появляется по роли из API.

Использован подход WireGuard Billing с отдельными SwiftUI- и Kotlin-клиентами. Вход через страницу сайта сохраняет CAPTCHA и существующие способы авторизации. iOS использует непостоянное хранилище WebKit, Android хранит cookies в закрытом хранилище приложения. Пароли приложение не сохраняет. При выходе сессия закрывается на сервере и локальные cookies удаляются.

## Сервер

Для нативного обзора нужен маршрут `api/mobile_dashboard.php` из этой ветки. Без сессии он возвращает HTTP 401. Он выдаёт только машины текущего пользователя, роль из БД и показатели активных гипервизоров, доступных пользователям. Учётные данные узлов и внутренние адреса API в ответ не включены. Пока маршрут не установлен через пакет обновления, вход на сайт доступен, но нативный обзор не загрузится.

## iPhone

Откройте `ios/HomeVlad.xcodeproj` в Xcode, выберите схему `HomeVlad`.

- Debug обращается к `https://dev.homevlad.ru`, идентификатор `ru.homevlad.cloud.dev`.
- Release обращается к `https://homevlad.ru`, идентификатор `ru.homevlad.cloud`.
- Минимальная версия iOS — 17.
- Для установки на физическое устройство выберите свою команду в Signing & Capabilities.
- Для симулятора подпись не требуется.

Если `xcode-select` указывает на Command Line Tools, задайте путь для команды сборки.

```sh
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer xcodebuild \
  -project mobile/ios/HomeVlad.xcodeproj -scheme HomeVlad \
  -sdk iphonesimulator -configuration Debug \
  -derivedDataPath /tmp/homevlad-ios-build CODE_SIGNING_ALLOWED=NO build
```

## Android

Откройте `android` в Android Studio. Нужны JDK 17, Android SDK 35 и Build Tools. Путь к SDK задаётся через `ANDROID_HOME` или локальный `local.properties`.

- Debug использует dev.homevlad.ru и идентификатор `ru.homevlad.cloud.dev`.
- Release использует homevlad.ru и идентификатор `ru.homevlad.cloud`.
- Минимальная версия Android — 8.0, API 26.
- Для распространения Release настройте собственную подпись. Ключи подписи хранятся вне репозитория.

```sh
cd mobile/android
./gradlew :app:assembleDebug
```

APK находится в `app/build/outputs/apk/debug/app-debug.apk`.

## Проверка перед распространением

На dev-сайте проверьте вход, CAPTCHA, обычного пользователя и администратора, список машин, переходы в кабинет, консоли QEMU/LXC и выход. Проверьте отказ доступа после отзыва роли, истечение сессии и работу при недоступной сети. Сборка исходников сама по себе не подтверждает эти сценарии.
