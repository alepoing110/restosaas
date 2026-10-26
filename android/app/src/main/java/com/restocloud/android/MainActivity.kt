package com.restocloud.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.Logout
import androidx.compose.material.icons.outlined.ReceiptLong
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.Locale

private val Forest = Color(0xFF132B25)
private val Moss = Color(0xFF326352)
private val Citrus = Color(0xFFD9FF70)
private val Cream = Color(0xFFF7F4EC)
private val Mist = Color(0xFFE8E5DB)
private val Muted = Color(0xFF66716B)
private val Coral = Color(0xFFE66643)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { RestoCloudApp() }
    }
}

private data class Session(val name: String, val role: String, val tenant: String, val branch: String, val csrf: String)
private data class Order(val customer: String, val total: Double, val state: String, val itemCount: Int)
private data class Product(val name: String, val type: String, val price: Double, val stock: Int)
private data class AppState(
    val loading: Boolean = true,
    val session: Session? = null,
    val orders: List<Order> = emptyList(),
    val products: List<Product> = emptyList(),
    val error: String? = null,
)

@Composable
private fun RestoCloudApp() {
    val api = remember { RestoApi() }
    val scope = rememberCoroutineScope()
    var state by remember { mutableStateOf(AppState()) }

    suspend fun load(session: Session) {
        runCatching { api.loadPos() }
            .onSuccess { (orders, products) -> state = AppState(session = session, orders = orders, products = products) }
            .onFailure { state = AppState(error = it.message ?: "No se pudo cargar el panel.") }
    }

    LaunchedEffect(Unit) {
        runCatching { api.restore() }
            .onSuccess { restored -> if (restored == null) state = AppState(loading = false) else load(restored) }
            .onFailure { state = AppState(loading = false) }
    }

    RestoCloudTheme {
        when {
            state.loading -> LoadingScreen()
            state.session == null -> LoginScreen(
                error = state.error,
                onLogin = { email, password ->
                    state = AppState(loading = true)
                    scope.launch {
                        runCatching { api.login(email, password) }
                            .onSuccess { load(it) }
                            .onFailure { state = AppState(error = it.message ?: "No se pudo iniciar sesión.") }
                    }
                },
            )
            else -> {
                val currentSession = requireNotNull(state.session)
                DashboardScreen(
                    session = currentSession,
                    orders = state.orders,
                    products = state.products,
                    onRefresh = {
                        state = state.copy(loading = true)
                        scope.launch { load(currentSession) }
                    },
                    onLogout = {
                        state = state.copy(loading = true)
                        scope.launch {
                            runCatching { api.logout(currentSession) }
                            state = AppState(loading = false)
                        }
                    },
                )
            }
        }
    }
}

@Composable
private fun RestoCloudTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = Forest,
            onPrimary = Cream,
            primaryContainer = Citrus,
            onPrimaryContainer = Forest,
            secondary = Moss,
            background = Cream,
            onBackground = Forest,
            surface = Color.White,
            onSurface = Forest,
            surfaceVariant = Mist,
            onSurfaceVariant = Muted,
        ),
        content = content,
    )
}

@Composable
private fun LoadingScreen() = Box(
    modifier = Modifier.fillMaxSize().background(Forest),
    contentAlignment = Alignment.Center,
) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        CircularProgressIndicator(color = Citrus)
        Spacer(Modifier.height(18.dp))
        Text("Preparando tu turno", color = Cream, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun LoginScreen(error: String?, onLogin: (String, String) -> Unit) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    Box(
        modifier = Modifier.fillMaxSize().background(Forest),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                modifier = Modifier.size(76.dp).clip(CircleShape).background(Citrus),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Outlined.Storefront, null, tint = Forest, modifier = Modifier.size(38.dp))
            }
            Spacer(Modifier.height(14.dp))
            Text("RestoCloud", color = Cream, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("Operación clara. Servicio en movimiento.", color = Color(0xFFC9D6D0), fontSize = 14.sp)
            Spacer(Modifier.height(28.dp))
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
            ) {
                Column(Modifier.padding(20.dp)) {
                    Text("Iniciar sesión", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    Text("Accede a la operación de tu sucursal.", color = Muted, fontSize = 13.sp)
                    Spacer(Modifier.height(18.dp))
                    OutlinedTextField(
                        value = email,
                        onValueChange = { email = it },
                        label = { Text("Correo") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(12.dp))
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("Contraseña") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    if (!error.isNullOrBlank()) {
                        Text(error, color = Coral, fontSize = 13.sp, modifier = Modifier.padding(top = 12.dp))
                    }
                    Button(
                        onClick = { onLogin(email.trim(), password) },
                        enabled = email.isNotBlank() && password.isNotBlank(),
                        modifier = Modifier.fillMaxWidth().padding(top = 20.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Citrus, contentColor = Forest),
                    ) { Text("Entrar a mi sucursal", fontWeight = FontWeight.Bold) }
                }
            }
        }
    }
}

private enum class DashboardTab(val label: String) { HOME("Inicio"), ORDERS("Pedidos"), CATALOG("Catálogo") }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DashboardScreen(
    session: Session,
    orders: List<Order>,
    products: List<Product>,
    onRefresh: () -> Unit,
    onLogout: () -> Unit,
) {
    var tab by remember { mutableStateOf(DashboardTab.HOME) }
    var query by remember { mutableStateOf("") }
    var category by remember { mutableStateOf("Todos") }
    val categories = remember(products) { listOf("Todos") + products.map { it.type.ifBlank { "General" } }.distinct().sorted() }
    val filteredProducts = products.filter {
        (category == "Todos" || it.type == category) && it.name.contains(query, ignoreCase = true)
    }

    Scaffold(
        containerColor = Cream,
        bottomBar = {
            NavigationBar(containerColor = Color.White) {
                DashboardTab.entries.forEach { item ->
                    val selected = tab == item
                    NavigationBarItem(
                        selected = selected,
                        onClick = { tab = item },
                        icon = {
                            when (item) {
                                DashboardTab.HOME -> Icon(Icons.Outlined.Storefront, item.label)
                                DashboardTab.ORDERS -> BadgedBox(badge = { if (orders.isNotEmpty()) Badge { Text("${orders.size}") } }) { Icon(Icons.Outlined.ReceiptLong, item.label) }
                                DashboardTab.CATALOG -> Icon(Icons.Outlined.Inventory2, item.label)
                            }
                        },
                        label = { Text(item.label) },
                    )
                }
            }
        },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(top = padding.calculateTopPadding(), bottom = padding.calculateBottomPadding() + 18.dp),
        ) {
            item {
                DashboardHeader(session, onRefresh, onLogout)
            }
            when (tab) {
                DashboardTab.HOME -> {
                    item { LiveSnapshot(orders.size, products.size, session) }
                    item { SectionTitle("Pedidos en cocina", "${orders.size} activos") }
                    if (orders.isEmpty()) item { EmptyCard("No hay pedidos activos en este momento.") }
                    else items(orders) { OrderCard(it) }
                    item { SectionTitle("Catálogo de hoy", "${products.size} disponibles") }
                    items(products.take(6)) { ProductCard(it) }
                }
                DashboardTab.ORDERS -> {
                    item { SectionTitle("Todos los pedidos activos", "${orders.size} en servicio") }
                    if (orders.isEmpty()) item { EmptyCard("No hay pedidos activos en este momento.") }
                    else items(orders) { OrderCard(it) }
                }
                DashboardTab.CATALOG -> {
                    item {
                        OutlinedTextField(
                            value = query,
                            onValueChange = { query = it },
                            modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 12.dp),
                            placeholder = { Text("Buscar producto o categoría") },
                            leadingIcon = { Icon(Icons.Outlined.Search, null) },
                            singleLine = true,
                            shape = RoundedCornerShape(16.dp),
                            colors = OutlinedTextFieldDefaults.colors(unfocusedContainerColor = Color.White, focusedContainerColor = Color.White),
                        )
                    }
                    item {
                        Row(
                            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 20.dp),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            categories.forEach { item ->
                                AssistChip(
                                    onClick = { category = item },
                                    label = { Text(item.replaceFirstChar { it.uppercase() }) },
                                    colors = AssistChipDefaults.assistChipColors(
                                        containerColor = if (category == item) Citrus else Color.White,
                                        labelColor = Forest,
                                    ),
                                )
                            }
                        }
                    }
                    item { SectionTitle("Productos", "${filteredProducts.size} resultados") }
                    if (filteredProducts.isEmpty()) item { EmptyCard("No hay coincidencias para esta búsqueda.") }
                    else items(filteredProducts) { ProductCard(it) }
                }
            }
        }
    }
}

@Composable
private fun DashboardHeader(session: Session, onRefresh: () -> Unit, onLogout: () -> Unit) {
    Column(Modifier.fillMaxWidth().background(Forest).padding(horizontal = 20.dp, vertical = 18.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("SUCURSAL ACTIVA", color = Citrus, fontSize = 11.sp, fontWeight = FontWeight.Bold, letterSpacing = 1.sp)
                Text(session.tenant, color = Cream, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("${session.branch} | ${session.role}", color = Color(0xFFB9C9C1), fontSize = 13.sp)
            }
            IconButton(onClick = onLogout) { Icon(Icons.Outlined.Logout, "Cerrar sesión", tint = Citrus) }
        }
        TextButton(onClick = onRefresh, contentPadding = PaddingValues(top = 12.dp, end = 12.dp)) {
            Text("Actualizar estado", color = Citrus, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun LiveSnapshot(orderCount: Int, productCount: Int, session: Session) {
    Card(
        modifier = Modifier.fillMaxWidth().padding(20.dp),
        shape = RoundedCornerShape(22.dp),
        colors = CardDefaults.cardColors(containerColor = Color.Transparent),
    ) {
        Box(
            Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Moss, Forest))).padding(20.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Surface(shape = RoundedCornerShape(8.dp), color = Citrus) {
                        Text("SERVICIO EN VIVO", color = Forest, fontSize = 10.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp))
                    }
                    Spacer(Modifier.height(10.dp))
                    Text("Hola, ${session.name.ifBlank { "equipo" }}", color = Cream, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text("${if (orderCount == 0) "Todo en orden por ahora." else "$orderCount pedidos requieren atención."}", color = Color(0xFFD4E0DA), fontSize = 13.sp)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("$orderCount", color = Citrus, fontSize = 34.sp, fontWeight = FontWeight.Black)
                    Text("pedidos", color = Cream, fontSize = 12.sp)
                    Text("$productCount productos", color = Color(0xFFD4E0DA), fontSize = 12.sp)
                }
            }
        }
    }
}

@Composable
private fun SectionTitle(title: String, detail: String) = Row(
    modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
    verticalAlignment = Alignment.CenterVertically,
) {
    Text(title, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
    Text(detail, color = Muted, fontSize = 12.sp)
}

@Composable
private fun OrderCard(order: Order) = Card(
    modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 6.dp),
    shape = RoundedCornerShape(18.dp),
    colors = CardDefaults.cardColors(containerColor = Color.White),
) {
    Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(48.dp).clip(RoundedCornerShape(14.dp)).background(Color(0xFFFFE0D6)), contentAlignment = Alignment.Center) {
            Icon(Icons.Outlined.ReceiptLong, null, tint = Coral)
        }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(order.customer, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("${order.itemCount} productos | ${order.state}", color = Muted, fontSize = 13.sp)
        }
        Text(money(order.total), fontWeight = FontWeight.Black)
    }
}

@Composable
private fun ProductCard(product: Product) = Card(
    modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 6.dp),
    shape = RoundedCornerShape(18.dp),
    colors = CardDefaults.cardColors(containerColor = Color.White),
) {
    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        val initial = product.name.firstOrNull()?.uppercase() ?: "P"
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(15.dp)).background(productColor(product.type)), contentAlignment = Alignment.Center) {
            Text(initial, color = Forest, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
        }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(product.name, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("${product.type} | Stock ${product.stock}", color = Muted, fontSize = 13.sp)
        }
        Text(money(product.price), fontWeight = FontWeight.Black)
    }
}

@Composable
private fun EmptyCard(message: String) = Card(
    modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
    shape = RoundedCornerShape(18.dp),
    colors = CardDefaults.cardColors(containerColor = Mist),
) {
    Text(message, color = Muted, modifier = Modifier.padding(18.dp))
}

private fun productColor(type: String) = when (type.lowercase()) {
    "bebida", "refresco" -> Color(0xFFCAE9FF)
    "postre" -> Color(0xFFFFE0EB)
    "sopa" -> Color(0xFFFFE7B5)
    else -> Color(0xFFDFF4C3)
}

private fun money(value: Double) = String.format(Locale.US, "Bs %.2f", value)

private class MemoryCookieJar : CookieJar {
    private val cookies = mutableListOf<Cookie>()
    override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        synchronized(this.cookies) {
            this.cookies.removeAll { old -> cookies.any { it.name == old.name && it.domain == old.domain && it.path == old.path } }
            this.cookies.addAll(cookies)
        }
    }
    override fun loadForRequest(url: HttpUrl): List<Cookie> = synchronized(cookies) { cookies.filter { it.matches(url) } }
    fun clear() = synchronized(cookies) { cookies.clear() }
}

private class RestoApi {
    private val cookieJar = MemoryCookieJar()
    private val client = OkHttpClient.Builder().cookieJar(cookieJar).build()
    private val jsonMedia = "application/json; charset=utf-8".toMediaType()
    private var csrfToken: String? = null

    suspend fun restore(): Session? = request("auth_me").takeIf { it.optBoolean("authenticated") }?.toSession()
    suspend fun login(email: String, password: String): Session = request("auth_login", JSONObject().put("email", email).put("password", password)).toSession()
    suspend fun logout(session: Session) { request("auth_logout", JSONObject(), csrfToken ?: session.csrf); csrfToken = null; cookieJar.clear() }

    suspend fun loadPos(): Pair<List<Order>, List<Product>> {
        val response = request("get_pos_state")
        val orders = response.optJSONArray("activeOrders").toObjects { item ->
            Order(item.optString("customer", "Pedido"), item.optDouble("total"), item.optString("serviceState", "pendiente"), item.optJSONArray("items")?.length() ?: 0)
        }
        val products = response.optJSONArray("products").toObjects { item ->
            Product(item.optString("name", "Producto"), item.optString("type", "producto"), item.optDouble("price"), item.optInt("stock"))
        }
        return orders to products
    }

    private suspend fun request(action: String, body: JSONObject? = null, csrf: String? = null): JSONObject = withContext(Dispatchers.IO) {
        val url = BuildConfig.API_BASE_URL.toHttpUrl().newBuilder().addPathSegment("api.php").addQueryParameter("action", action).build()
        val builder = Request.Builder().url(url)
        if (body == null) builder.get() else {
            builder.post(body.toString().toRequestBody(jsonMedia))
            if (csrf != null) builder.header("X-CSRF-Token", csrf)
        }
        client.newCall(builder.build()).execute().use { response ->
            val payload = response.body?.string().orEmpty()
            if (payload.isBlank()) throw IOException("El servidor cerró la conexión sin responder. Verifique el despliegue de producción.")
            val data = try { JSONObject(payload) } catch (_: Exception) { throw IOException("El servidor respondió un formato inesperado (HTTP ${response.code}).") }
            if (!response.isSuccessful || data.optString("status") != "success") throw IOException(data.optString("message").ifBlank { "No se pudo completar la solicitud." })
            data.optString("csrf_token").takeIf { it.isNotBlank() }?.let { csrfToken = it }
            data
        }
    }
}

private fun JSONObject.toSession(): Session {
    val user = optJSONObject("authUser") ?: JSONObject()
    val tenant = optJSONObject("tenant") ?: JSONObject()
    val branch = optJSONObject("branch") ?: JSONObject()
    return Session(user.optString("name", user.optString("email")), user.optString("role"), tenant.optString("name"), branch.optString("name"), optString("csrf_token"))
}

private fun <T> JSONArray?.toObjects(mapper: (JSONObject) -> T): List<T> = if (this == null) emptyList() else List(length()) { mapper(optJSONObject(it) ?: JSONObject()) }
