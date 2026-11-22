package ru.homevlad.cloud

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.net.URLEncoder
import java.util.UUID

private fun directoryPath(resource: String, vararg parameters: Pair<String, String>): String =
    "$mobileAdminApi?resource=$resource" + parameters.joinToString("") { (key, value) ->
        "&$key=${URLEncoder.encode(value, "UTF-8")}"
    }

private fun directoryMoney(value: String): String = value.toBigDecimalOrNull()?.let(::money) ?: value

private val accountStates = listOf("" to "Все", "active" to "Активные", "inactive" to "Неактивные")
private val paymentStates = listOf("" to "Все", "pending" to "На проверке",
    "completed" to "Подтверждённые", "failed" to "Отклонённые")
private val accountTypes = listOf("individual" to "Физическое лицо", "entrepreneur" to "ИП", "legal" to "Организация")
private val amountPattern = Regex("""^(0|[1-9][0-9]{0,7})([.,][0-9]{1,2})?$""")

@Composable
private fun DirectoryPicker(label: String, value: String, options: List<Pair<String, String>>, select: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    Box {
        OutlinedButton(onClick = { expanded = true }) {
            Text("$label: ${options.firstOrNull { it.first == value }?.second ?: value}")
        }
        DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { (id, title) ->
                DropdownMenuItem(text = { Text(title) }, onClick = { expanded = false; select(id) })
            }
        }
    }
}

@Composable
private fun DirectoryList(
    model: CloudModel,
    revision: Int,
    resource: String,
    title: String,
    prompt: String,
    empty: String,
    query: String,
    setQuery: (String) -> Unit,
    filter: String,
    setFilter: (String) -> Unit,
    statuses: List<Pair<String, String>>,
    userId: Int? = null,
    actions: @Composable RowScope.() -> Unit = {},
    row: @Composable (JSONObject) -> Unit
) {
    var records by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    var next by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(true) }
    var generation by remember { mutableIntStateOf(0) }
    var retry by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()

    suspend fun load(before: Int? = null) {
        val request = ++generation
        busy = true
        error = null
        try {
            val parameters = mutableListOf("q" to query.trim(), "status" to filter)
            if (userId != null) parameters.add("user" to userId.toString())
            if (before != null) parameters.add("before" to before.toString())
            val data = model.request(directoryPath(resource, *parameters.toTypedArray()))
            if (request == generation) {
                val entries = data.getJSONArray("items")
                val batch = List(entries.length(), entries::getJSONObject)
                records = if (before == null) batch else records + batch
                next = data.optInt("next").takeIf { it > 0 }
            }
        } catch (failure: Exception) {
            if (failure is CancellationException) throw failure
            if (request == generation) error = failure.localizedMessage ?: "Не удалось загрузить данные"
        } finally { if (request == generation) busy = false }
    }

    LaunchedEffect(query, filter, revision, retry) {
        generation++
        records = emptyList()
        next = null
        error = null
        busy = true
        if (query.isNotBlank()) delay(250)
        load()
    }

    LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Text(title, style = MaterialTheme.typography.headlineSmall)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), content = actions)
        }
        item { OutlinedTextField(query, { setQuery(it.take(255)) }, Modifier.fillMaxWidth(),
            label = { Text(prompt) }, singleLine = true) }
        if (statuses.isNotEmpty()) item { DirectoryPicker("Статус", filter, statuses, setFilter) }
        items(records, key = { it.getInt("id") }) { row(it) }
        if (busy) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        error?.let { message -> item {
            Text(message, color = MaterialTheme.colorScheme.error)
            TextButton(onClick = { retry++ }) { Text("Повторить") }
        } }
        if (records.isEmpty() && !busy && error == null) item { Text(empty) }
        next?.let { cursor -> if (!busy && error == null) item {
            TextButton(onClick = { scope.launch { if (!busy) load(cursor) } }) { Text("Загрузить ещё") }
        } }
    }
}

@Composable
internal fun AdminUserDirectory(model: CloudModel, revision: Int) {
    var search by remember { mutableStateOf("") }
    var filter by remember { mutableStateOf("") }
    var account by remember { mutableStateOf<Int?>(null) }
    var showPayments by remember { mutableStateOf(false) }
    val id = account
    if (id == null) {
        DirectoryList(model, revision, "accounts", "Пользователи", "Имя, email или ID", "Пользователи не найдены",
            search, { search = it }, filter, { filter = it }, accountStates,
            actions = { TextButton(onClick = { account = 0 }) { Text("Добавить пользователя") } }) { user ->
            DirectoryAccountRow(user) { account = user.getInt("id") }
        }
    } else if (showPayments) {
        AdminPaymentDirectory(model, revision, id) { showPayments = false }
    } else {
        AdminAccountEditor(model, id, close = { account = null }, payments = { showPayments = true })
    }
}

@Composable
private fun DirectoryAccountRow(user: JSONObject, open: () -> Unit) {
    Card(onClick = open, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            val name = user.getString("name")
            val email = user.getString("email")
            Text(name, style = MaterialTheme.typography.titleMedium)
            if (name != email) Text(email)
            val role = if (user.getBoolean("isAdmin")) "Администратор" else "Пользователь"
            val state = if (user.getBoolean("isActive")) "Активен" else "Неактивен"
            Text("$role · $state · ${directoryMoney(user.getString("balance"))}", style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun DirectoryForm(title: String, busy: Boolean, close: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    BackHandler { if (!busy) close() }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)) {
        TextButton(onClick = close, enabled = !busy) { Text("Назад") }
        Text(title, style = MaterialTheme.typography.headlineSmall)
        content()
    }
}

@Composable
private fun DirectoryField(
    label: String,
    value: String,
    update: (String) -> Unit,
    enabled: Boolean,
    keyboard: KeyboardType = KeyboardType.Text,
    secret: Boolean = false,
    singleLine: Boolean = true
) {
    OutlinedTextField(value, update, Modifier.fillMaxWidth(), label = { Text(label) }, enabled = enabled,
        singleLine = singleLine, keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        visualTransformation = if (secret) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None)
}

@Composable
private fun DirectorySwitch(label: String, checked: Boolean, enabled: Boolean, update: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = update, enabled = enabled)
    }
}

@Composable
private fun AdminAccountEditor(model: CloudModel, id: Int, close: () -> Unit, payments: () -> Unit) {
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf(id == 0) }
    var retry by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var email by remember { mutableStateOf("") }
    var name by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("individual") }
    var company by remember { mutableStateOf("") }
    var inn by remember { mutableStateOf("") }
    var kpp by remember { mutableStateOf("") }
    var active by remember { mutableStateOf(false) }
    var admin by remember { mutableStateOf(false) }
    var initialAdmin by remember { mutableStateOf(false) }
    var ownAccount by remember { mutableStateOf(false) }
    var balance by remember { mutableStateOf("") }
    var registration by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var repeated by remember { mutableStateOf("") }
    var confirmRole by remember { mutableStateOf(false) }

    LaunchedEffect(id, retry) {
        if (id == 0) return@LaunchedEffect
        error = null
        try {
            val data = model.request(directoryPath("account", "id" to id.toString()))
            email = data.getString("email")
            name = data.getString("fullName")
            type = data.getString("type")
            company = data.getString("company")
            inn = data.getString("inn")
            kpp = data.getString("kpp")
            active = data.getBoolean("isActive")
            admin = data.getBoolean("isAdmin")
            initialAdmin = admin
            ownAccount = data.getBoolean("isSelf")
            balance = data.getString("balance")
            registration = data.getString("createdAt")
            loaded = true
        } catch (failure: Exception) {
            if (failure is CancellationException) throw failure
            error = failure.localizedMessage
        }
    }

    fun save() {
        if (busy) return
        busy = true
        error = null
        scope.launch {
            try {
                val payload = JSONObject().put("action", "account_save").put("id", id).put("email", email)
                    .put("fullName", name).put("type", type).put("company", company).put("inn", inn).put("kpp", kpp)
                    .put("isActive", active).put("isAdmin", admin)
                if (id == 0) payload.put("password", password)
                model.request(mobileAdminApi, payload)
                password = ""; repeated = ""
                close()
            } catch (failure: Exception) {
                if (failure is CancellationException) throw failure
                error = failure.localizedMessage
            } finally { busy = false }
        }
    }

    DirectoryForm(if (id == 0) "Новый пользователь" else "Пользователь №$id", busy, close) {
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (loaded) {
            DirectoryField("Имя", name, { name = it.take(100) }, !busy)
            DirectoryField("Email", email, { email = it.take(255) }, !busy, KeyboardType.Email)
            if (busy) Text(accountTypes.firstOrNull { it.first == type }?.second ?: type)
            else DirectoryPicker("Тип клиента", type, accountTypes) { type = it }
            if (type != "individual") {
                DirectoryField("Компания", company, { company = it.take(255) }, !busy)
                DirectoryField("ИНН", inn, { inn = it.filter(Char::isDigit).take(12) }, !busy, KeyboardType.Number)
                if (type == "legal") DirectoryField("КПП", kpp, { kpp = it.filter(Char::isDigit).take(9) }, !busy, KeyboardType.Number)
            }
            DirectorySwitch("Учётная запись активна", active, !busy && !ownAccount) { active = it }
            DirectorySwitch("Администратор", admin, !busy && !ownAccount) { admin = it }
            if (id == 0) {
                DirectoryField("Пароль", password, { password = it }, !busy, KeyboardType.Password, secret = true)
                DirectoryField("Повторите пароль", repeated, { repeated = it }, !busy, KeyboardType.Password, secret = true)
            }
            Button(enabled = !busy && email.isNotBlank() && (id != 0 ||
                (password.length >= 8 && password.toByteArray().size <= 72 && password == repeated)),
                onClick = { if (admin != initialAdmin) confirmRole = true else save() }) { Text("Сохранить") }
            if (id != 0) {
                Text("Текущий баланс: ${directoryMoney(balance)}")
                if (registration.isNotEmpty()) Text("Регистрация: $registration")
                OutlinedButton(onClick = payments, enabled = !busy) { Text("Платежи пользователя") }
            }
        }
        if (busy || (!loaded && error == null)) LinearProgressIndicator(Modifier.fillMaxWidth())
        if (!loaded && error != null) TextButton(onClick = { retry++ }) { Text("Повторить") }
    }
    if (confirmRole) AlertDialog(onDismissRequest = { confirmRole = false },
        title = { Text(if (admin) "Выдать права администратора?" else "Снять права администратора?") },
        text = { Text(email) }, confirmButton = { TextButton(onClick = { confirmRole = false; save() }) { Text("Сохранить") } },
        dismissButton = { TextButton(onClick = { confirmRole = false }) { Text("Отмена") } })
}

@Composable
internal fun AdminPaymentDirectory(model: CloudModel, revision: Int, userId: Int? = null, close: (() -> Unit)? = null) {
    var search by remember { mutableStateOf("") }
    var filter by remember { mutableStateOf("") }
    var detail by remember { mutableStateOf<JSONObject?>(null) }
    var creating by remember { mutableStateOf(false) }
    val payment = detail
    when {
        creating -> AdminPaymentCreate(model, revision, userId) { creating = false }
        payment != null -> AdminPaymentDetail(model, payment) { detail = null }
        else -> {
            BackHandler(close != null) { close?.invoke() }
            DirectoryList(model, revision, "payments", "Платежи", "Номер, клиент или описание", "Платежи не найдены",
                search, { search = it }, filter, { filter = it }, paymentStates, userId,
                actions = {
                    if (close != null) TextButton(onClick = close) { Text("Назад") }
                    TextButton(onClick = { creating = true }) { Text("Добавить платёж") }
                }) { entry ->
                Card(onClick = { detail = entry }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(directoryMoney(entry.getString("amount")), style = MaterialTheme.typography.titleMedium)
                        Text("№${entry.getInt("id")} · ${status(entry.getString("status"))}")
                        Text(entry.getString("userName"))
                        Text(entry.getString("createdAt"), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

@Composable
private fun AdminPaymentDetail(model: CloudModel, summary: JSONObject, close: () -> Unit) {
    val scope = rememberCoroutineScope()
    val id = summary.getInt("id")
    var record by remember { mutableStateOf(summary) }
    var loaded by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var decision by remember { mutableStateOf<Boolean?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var retry by remember { mutableIntStateOf(0) }

    suspend fun load() {
        try {
            record = model.request(directoryPath("payment", "id" to id.toString()))
            loaded = true
            error = null
        } catch (failure: Exception) {
            if (failure is CancellationException) throw failure
            error = failure.localizedMessage
        }
    }

    LaunchedEffect(retry) { load() }
    DirectoryForm("Платёж №$id", busy, close) {
        Text(directoryMoney(record.getString("amount")), style = MaterialTheme.typography.headlineSmall)
        Text("Статус: ${status(record.getString("status"))}")
        listOf("userName" to "Клиент", "email" to "Email", "method" to "Способ", "description" to "Описание",
            "invoice" to "Счёт", "createdAt" to "Создан", "updatedAt" to "Обновлён",
            "paidAt" to "Оплачен", "controlWord" to "Контрольное слово").forEach { (key, label) ->
            val value = record.optString(key)
            if (value.isNotBlank()) Text("$label: ${if (key == "method" && value == "manual") "Ручной" else value}")
        }
        if (loaded && record.getString("status") == "pending") {
            Button(onClick = { decision = true }, enabled = !busy) { Text("Подтвердить и зачислить") }
            OutlinedButton(onClick = { decision = false }, enabled = !busy) { Text("Отклонить") }
        }
        notice?.let { Text(it) }
        error?.let {
            Text(it, color = MaterialTheme.colorScheme.error)
            TextButton(onClick = { retry++ }, enabled = !busy) { Text("Обновить данные") }
        }
        if (busy || (!loaded && error == null)) LinearProgressIndicator(Modifier.fillMaxWidth())
    }

    decision?.let { approve ->
        AlertDialog(onDismissRequest = { decision = null },
            title = { Text(if (approve) "Подтвердить платёж?" else "Отклонить платёж?") },
            text = { Text("${record.getString("userName")} · ${directoryMoney(record.getString("amount"))}") },
            confirmButton = { TextButton(onClick = {
                decision = null
                busy = true
                scope.launch {
                    try {
                        val action = if (approve) "payment_approve" else "payment_reject"
                        notice = model.request(mobileAdminApi, JSONObject().put("action", action).put("id", id)).optString("notice")
                        loaded = false
                        load()
                        model.refresh()
                    } catch (failure: Exception) {
                        if (failure is CancellationException) throw failure
                        load()
                        error = failure.localizedMessage
                    } finally { busy = false }
                }
            }) { Text("Подтвердить действие") } },
            dismissButton = { TextButton(onClick = { decision = null }) { Text("Отмена") } })
    }
}

@Composable
private fun AdminPaymentCreate(model: CloudModel, revision: Int, fixedUser: Int?, close: () -> Unit) {
    val scope = rememberCoroutineScope()
    var selecting by remember { mutableStateOf(false) }
    var search by remember { mutableStateOf("") }
    var payer by remember { mutableStateOf<JSONObject?>(null) }
    var amount by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var attempted by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val requestId = remember { UUID.randomUUID().toString() }
    val user = fixedUser ?: payer?.getInt("id")
    val normalized = amount.trim().replace(',', '.')
    val validAmount = amountPattern.matches(normalized) && normalized.toBigDecimal().signum() > 0

    if (selecting) {
        BackHandler { selecting = false }
        DirectoryList(model, revision, "accounts", "Получатель", "Имя, email или ID", "Пользователи не найдены",
            search, { search = it }, "", {}, emptyList(),
            actions = { TextButton(onClick = { selecting = false }) { Text("Назад") } }) { candidate ->
            DirectoryAccountRow(candidate) { payer = candidate; selecting = false }
        }
        return
    }

    DirectoryForm("Новый платёж", busy, close) {
        if (fixedUser != null) Text("Клиент №$fixedUser")
        else {
            payer?.let { Text("${it.getString("name")} · ${it.getString("email")}") }
            OutlinedButton(onClick = { selecting = true }, enabled = !attempted) {
                Text(if (payer == null) "Выбрать пользователя" else "Изменить пользователя")
            }
        }
        DirectoryField("Сумма, ₽", amount, { amount = it.take(16) }, !attempted, KeyboardType.Decimal)
        DirectoryField("Описание", description, { description = it.take(255) }, !attempted, singleLine = false)
        Text("Платёж будет ожидать проверки. Баланс изменится после подтверждения.")
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        Button(enabled = !busy && user != null && validAmount && description.isNotBlank(), onClick = {
            busy = true
            attempted = true
            error = null
            scope.launch {
                try {
                    val body = JSONObject().put("action", "payment_add").put("userId", user)
                        .put("amount", normalized).put("description", description).put("requestId", requestId)
                    model.request(mobileAdminApi, body)
                    close()
                } catch (failure: Exception) {
                    if (failure is CancellationException) throw failure
                    if (failure is CloudHttpException && failure.status in 400..499) attempted = false
                    error = failure.localizedMessage
                } finally { busy = false }
            }
        }) { Text(if (attempted) "Повторить отправку" else "Добавить платёж") }
    }
}
