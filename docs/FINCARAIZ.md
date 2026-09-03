# Integración Fincaraíz

Implementación basada en el contrato OpenAPI [`Fincaraiz.com.co/Integradores` 1.0.0](https://app.swaggerhub.com/apis-docs/Fincaraiz.com.co/Integradores/1.0.0) publicado en SwaggerHub.

## Ambientes

| `FINCARAIZ_ENV` | Base URL | Uso |
|---|---|---|
| `mock` | `https://virtserver.swaggerhub.com/Fincaraiz.com.co/Integradores/1.0.0` | Valida transporte y forma del contrato; no crea avisos reales. |
| `qa` | `https://api-integrators.frcol.io/management/api/1.0` | Pruebas con credenciales QA de Fincaraíz. Es el valor predeterminado. |
| `production` | `https://msi-infofinca.fincaraiz.com.co/management/api/1.0` | Producción; activar solo después de aprobar QA. |

`FINCARAIZ_API_URL` permite sobrescribir la URL, pero normalmente no debe definirse.

## Configuración

```dotenv
FINCARAIZ_ENV=qa
FINCARAIZ_API_KEY=
FINCARAIZ_CLIENT_ID=
FINCARAIZ_CLIENT_AGENT=

FINCARAIZ_CONTACT_EMAIL=
FINCARAIZ_CONTACT_PHONE=
FINCARAIZ_CONTACT_WHATSAPP=
FINCARAIZ_SHOW_EXACT_ADDRESS=false
FINCARAIZ_DUAL_OFFER=rent
FINCARAIZ_WEBHOOK_ID=
FINCARAIZ_WEBHOOK_VERIFY_TOKEN=
FINCARAIZ_WEBHOOK_URL=https://portal-core.example.com/api/portals/fincaraiz/webhook
```

- `API_KEY`: token del integrador; se envía exclusivamente en el encabezado `apikey`.
- `CLIENT_ID`: UUID del cliente asociado al integrador, obtenido con `GET /client`.
- `CLIENT_AGENT`: identificador numérico de la sucursal, obtenido con `GET /client/{client_id}/agent`. Es opcional si Fincaraíz tiene un agente predeterminado.
- Los datos de contacto globales tienen prioridad sobre los del asesor del inmueble.
- Para inmuebles “venta o arriendo”, Fincaraíz admite una sola oferta por aviso. `DUAL_OFFER` elige `sale` o `rent`.
- La activación automática queda deshabilitada durante pruebas. Después de crear y verificar el aviso se usa la acción **Activar**.

Después de cambiar variables:

```bash
php artisan config:clear
php artisan route:clear
```

Las mismas credenciales de QA también se pueden guardar desde **Integraciones → Fincaraíz → Configurar QA**. La API key queda cifrada en `portal_credentials.access_token`; el panel nunca vuelve a mostrarla y permite conservarla dejando el campo vacío. Las variables de entorno siguen funcionando como configuración predeterminada.

## Varios clientes asociados al mismo integrador

Es normal que una API key muestre dos o más clientes: el contrato de Fincaraíz define `GET /client` como el listado de **todos los clientes asociados al integrador**. Esto no significa que los catálogos estén mezclados.

Cada instalación del panel selecciona un único cliente mediante `FINCARAIZ_CLIENT_ID` o desde **Integraciones → Fincaraíz → Configurar**. El backend filtra la respuesta de `/client`, muestra solamente ese cliente y consulta sus agentes con `GET /client/{client_id}/agent`. Los avisos se consultan usando ese mismo cliente.

No se debe eliminar ni desvincular el otro cliente en Fincaraíz si será utilizado por otra empresa o base de datos. La separación recomendada es:

| Instalación | Base del panel | Base WordPress | `FINCARAIZ_CLIENT_ID` | Webhook |
|---|---|---|---|---|
| Servidor A | Independiente | Catálogo A | Cliente A | URL/token A |
| Servidor B | Independiente | Catálogo B | Cliente B | URL/token B |

La API key puede ser la misma si Fincaraíz la autorizó para ambos clientes. El `CLIENT_ID`, el agente, las bases de datos, los estados de sincronización y el webhook deben corresponder a cada instalación.

## Reglas importantes del contrato

1. Todos los endpoints del portal, salvo el receptor de webhook propio, requieren el encabezado `apikey`.
2. Toda consulta `GET` incluye un parámetro dinámico (`sucasa-cache=<uuid>`) para evitar respuestas antiguas del caché de Fincaraíz.
3. `POST /listing`, `PATCH /listing` y `PATCH /listing/status` reciben arreglos, incluso cuando se envía un solo inmueble.
4. Crear, actualizar o cambiar estado es asíncrono: la primera respuesta solo entrega `task.id`.
5. La operación termina cuando `GET /task/{task_id}` reporta `COMPLETED`, `ERROR` o `FORWARDED`.
6. `listing_id` se obtiene del contenido de la tarea y es el identificador requerido para actualizar, desactivar o activar.
7. Un inmueble creado queda pendiente. Se activa posteriormente con `PATCH /listing/status` y estado `ACTIVE`.
8. El contrato enumera `ACTIVE` y `DELETED`, mientras la descripción oficial también documenta `DISABLED`. El botón **Despublicar** usa `DISABLED` para no eliminar permanentemente el aviso.
9. `POST /validate-listing` elimina en Fincaraíz los avisos activos que no estén incluidos en el inventario enviado. El cliente lo soporta, pero no se expone en el panel para evitar borrados accidentales.
10. `/category` está marcado como obsoleto. Las características usadas por el mapper provienen del catálogo documentado en `ListingPOST`.

## Payload de inmueble

El preflight valida antes de enviar los campos obligatorios del contrato:

- `external_code`, `client_id`, `offer`, `property_type`, `description` y `price`;
- `area`, `address`, latitud y longitud;
- `listing_contact` con correo y teléfono;
- códigos enumerados para condición, antigüedad, habitaciones, baños, piso y garajes;
- hasta 30 imágenes con una sola principal.

El UUID del barrio (`location_main_id`) se obtiene con `GET /location/{name}`. En el panel, abre el inmueble, entra a **Portales → Fincaraíz → Preflight** y pulsa **Buscar y homologar barrio**. Busca por nombre, confirma ciudad/departamento y elige un resultado de tipo `NEIGHBOURHOOD`, `COMMUNE` o `CITY` cuando esa ubicación corresponda al barrio local. El panel guarda el UUID para todos los inmuebles de ese barrio local. Si aún no está homologado, el payload sigue siendo válido con coordenadas y muestra una advertencia. Los IDs ficticios del seeder no se envían.

También se puede administrar todo el catálogo desde **Barrios → Fincaraíz**. La migración agrega en WordPress:

- `wp_jet_cct_barrios.fincaraiz_location_id`;
- `wp_jet_cct_barrios.fincaraiz_location_name`;
- `wp_jet_cct_barrios.fincaraiz_location_type`.

La selección permite guardar resultados oficiales de tipo `NEIGHBOURHOOD`, `COMMUNE` o `CITY` y sincroniza el UUID con `portal_mappings`, que es la homologación consumida por el preflight de todos los inmuebles del mismo barrio local.

## Flujo de prueba recomendado

1. Solicitar a Fincaraíz una API key, Client ID y, si aplica, Agent ID de **QA**.
2. Configurar las variables con `FINCARAIZ_ENV=qa`.
3. En Integraciones, pulsar **Probar API**. Debe mostrar el cliente, cupo y agentes.
4. Revisar `GET /api/portals/properties/{code}/fincaraiz/payload`; los errores bloquean el envío y las advertencias no.
5. Publicar un solo inmueble de prueba. La respuesta debe quedar `pending` con un `task_id`.
6. Pulsar **Verificar** hasta que la tarea termine. Al crear, quedará pendiente de activación y almacenará `listing_id`.
7. Pulsar **Activar** y verificar la nueva tarea.
8. Confirmar el aviso con `GET /api/portals/fincaraiz/listings?search={code}`.
9. Probar **Actualizar**, **Despublicar** y **Activar** antes de promover a producción.

Para una prueba sin credenciales ni datos reales se puede usar temporalmente `FINCARAIZ_ENV=mock`, una API key cualquiera y UUIDs de prueba. El mock de SwaggerHub comprueba la forma HTTP, pero no sustituye QA: no procesa imágenes, cupos, moderación ni tareas reales.

## Webhook

El receptor público es:

```text
POST /api/portals/fincaraiz/webhook
```

Valida los encabezados `HUB.ID` y `VERIFY-TOKEN` con comparación segura y actualiza el estado por `external_code`. La suscripción se solicita desde:

```text
POST /api/portals/fincaraiz/webhook/subscribe
```

La URL debe ser HTTPS y accesible públicamente. Durante QA también se puede verificar manualmente con `/verify` sin habilitar el webhook.

## Paso a producción

1. Completar el flujo de QA con creación, consulta, actualización, despublicación y reactivación.
2. Confirmar con Fincaraíz el API key, Client ID, Agent ID, Webhook ID y cupos de producción.
3. Cambiar únicamente `FINCARAIZ_ENV=production` y las credenciales correspondientes; no reutilizar credenciales QA.
4. Limpiar la caché de configuración y comprobar que `/fincaraiz/status` reporta `production` antes de publicar.
5. Publicar y verificar un solo inmueble controlado.
6. Suscribir el webhook y comprobar sus encabezados.
7. Habilitar automatización o cargas masivas solo después de esa validación.

No se debe ejecutar `validate-listing` durante la puesta en marcha inicial.

## Montaje completo en un segundo servidor y otra base de datos

Esta secuencia monta una instalación aislada para el segundo cliente. Sustituye todos los valores entre `<...>` y no copies secretos en el repositorio.

### 1. Preparar DNS, servidor y bases

- PHP 8.2 o superior con extensiones de Laravel y MySQL, Composer 2, Node.js 20 o superior y npm.
- Un dominio HTTPS para el panel y otro para la API, por ejemplo `panel-otra.example.com` y `api-otra.example.com`.
- Una base MySQL vacía para Laravel (`DB_DATABASE`).
- La base WordPress/JetEngine que contiene el segundo catálogo (`WORDPRESS_DB_DATABASE`).
- El usuario MySQL del backend necesita permisos sobre su base Laravel y lectura sobre el catálogo WordPress. Durante la primera migración también necesita `ALTER` sobre `wp_jet_cct_barrios` para crear los tres campos de homologación de Fincaraíz.

Haz copia de seguridad de ambas bases antes de migrar. No apuntes el servidor B a la base Laravel ni a la base WordPress del servidor A.

### 2. Instalar el backend

```bash
git clone <URL_BACKEND> backend
cd backend
composer install --no-dev --optimize-autoloader
```

Crea `.env` mediante el administrador de secretos del servidor o a partir de una plantilla local segura. Configuración mínima:

```dotenv
APP_NAME="Panel de portales"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api-otra.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<BASE_LARAVEL_SERVIDOR_B>
DB_USERNAME=<USUARIO_LARAVEL_B>
DB_PASSWORD=<CLAVE_LARAVEL_B>

PROPERTIES_SOURCE=wordpress
AUTH_SOURCE=wordpress_funcionarios
SEED_DEMO_DATA=false
WORDPRESS_DB_HOST=127.0.0.1
WORDPRESS_DB_PORT=3306
WORDPRESS_DB_DATABASE=<BASE_WORDPRESS_CATALOGO_B>
WORDPRESS_DB_USERNAME=<USUARIO_WORDPRESS_B>
WORDPRESS_DB_PASSWORD=<CLAVE_WORDPRESS_B>

FRONTEND_URL=https://panel-otra.example.com
CORS_ALLOWED_ORIGINS=https://panel-otra.example.com
SANCTUM_STATEFUL_DOMAINS=panel-otra.example.com

FINCARAIZ_ENV=production
FINCARAIZ_API_KEY=<API_KEY_PRODUCCION>
FINCARAIZ_CLIENT_ID=<UUID_DEL_SEGUNDO_CLIENTE>
FINCARAIZ_CLIENT_AGENT=<ID_NUMERICO_DE_UN_AGENTE_DEL_SEGUNDO_CLIENTE>
FINCARAIZ_CONTACT_EMAIL=<CORREO_COMERCIAL>
FINCARAIZ_CONTACT_PHONE=<TELEFONO>
FINCARAIZ_CONTACT_WHATSAPP=<WHATSAPP>
FINCARAIZ_SHOW_EXACT_ADDRESS=false
FINCARAIZ_DUAL_OFFER=rent
FINCARAIZ_TIMEOUT=30

# Mantener apagado hasta terminar la prueba controlada.
FINCARAIZ_AUTO_SYNC=false
FINCARAIZ_AUTO_SYNC_LIMIT=20
FINCARAIZ_AUTO_SYNC_SCAN=500

# Deben ser propios del servidor B.
FINCARAIZ_WEBHOOK_ID=<ID_WEBHOOK_B>
FINCARAIZ_WEBHOOK_VERIFY_TOKEN=<TOKEN_ALEATORIO_LARGO_B>
FINCARAIZ_WEBHOOK_URL=https://api-otra.example.com/api/portals/fincaraiz/webhook
```

Genera una llave de aplicación distinta a la del primer servidor y prepara la base:

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=IntegrationSeeder --force
php artisan storage:link
php artisan optimize
```

El servidor web debe publicar únicamente `backend/public`; nunca la raíz del repositorio ni el archivo `.env`.

### 3. Evitar que una credencial copiada sobrescriba `.env`

La configuración guardada desde el panel es por usuario y tiene prioridad sobre `FINCARAIZ_API_KEY`, `FINCARAIZ_CLIENT_ID` y los demás valores del entorno. En una base Laravel nueva no habrá conflicto. Si se clonó una base existente, elimina o actualiza **desde la interfaz** la credencial Fincaraíz de ese usuario antes de probar; no reutilices el `portal_credentials` ni los `property_sync_statuses` del cliente A.

En **Integraciones → Fincaraíz → Configurar**, confirma que el Client ID visible sea el del segundo cliente. Al pulsar **Probar API**, debe aparecer exactamente un cliente. Si el UUID no pertenece a la API key, el panel mostrará un error explícito y no consultará agentes.

### 4. Obtener el agente correcto

El agente no es el UUID del cliente. Selecciona el Client ID del servidor B y ejecuta **Probar API**; el panel consulta `GET /client/{client_id}/agent`. Copia el identificador numérico del agente/sucursal que atenderá los avisos a `FINCARAIZ_CLIENT_AGENT` o guárdalo desde la configuración del panel.

### 5. Instalar el panel

```bash
git clone <URL_PANEL> panel
cd panel
npm ci
```

Crea `panel/.env.production`:

```dotenv
VITE_API_BASE_URL=https://api-otra.example.com/api
VITE_API_PROPS_URL=https://api-otra.example.com/api
VITE_SCRIPT_PHP_URL=https://api-otra.example.com/api/portals
VITE_FRONTEND_URL=https://panel-otra.example.com
VITE_APP_NAME="Panel de portales"
```

Luego compila y publica el contenido de `panel/dist`:

```bash
npm run build
```

El hosting debe servir `index.html` como fallback de la aplicación. Las rutas actuales usan hash (`#/...`), por lo que no requieren reescrituras adicionales para esas páginas.

### 6. Activar el programador

La sincronización automática depende del scheduler de Laravel. En Linux configura una sola entrada cron:

```cron
* * * * * cd /ruta/absoluta/backend && php artisan schedule:run >> /dev/null 2>&1
```

En Windows crea una tarea cada minuto que ejecute:

```text
C:\ruta\a\php.exe C:\ruta\absoluta\backend\artisan schedule:run
```

Con `FINCARAIZ_AUTO_SYNC=false` el scheduler puede estar activo sin publicar en Fincaraíz.

### 7. Validación segura antes de automatizar

1. Comprueba `GET https://api-otra.example.com/api/health`.
2. Inicia sesión y abre **Integraciones → Fincaraíz**.
3. Verifica ambiente `production`, Client ID del servidor B, un solo cliente visible, cupos y agentes correctos.
4. Homologa primero un barrio desde **Barrios → Fincaraíz**.
5. Elige un inmueble público controlado, ejecuta el preflight y corrige todos los errores.
6. Publícalo manualmente, espera la tarea, verifica que guarde su `listing_id` y actívalo.
7. Confirma el aviso tanto en el panel como en la Oficina Virtual de ese segundo cliente.
8. Actualiza un dato no crítico y verifica la actualización; luego prueba despublicar y reactivar.
9. Suscribe y prueba el webhook propio del servidor B.
10. Cuando todo sea correcto, activa **Sincronización automática** desde el panel o cambia `FINCARAIZ_AUTO_SYNC=true`, limpia caché y observa el primer lote con límite bajo.

Después de cualquier cambio en `.env` ejecuta:

```bash
php artisan optimize:clear
php artisan optimize
```

### 8. Lista de control para no mezclar clientes

- [ ] Base Laravel B distinta de la base Laravel A.
- [ ] Base WordPress B corresponde únicamente al catálogo B.
- [ ] `FINCARAIZ_CLIENT_ID` es el segundo UUID, no el del servidor A.
- [ ] `FINCARAIZ_CLIENT_AGENT` pertenece al segundo cliente.
- [ ] No se copiaron referencias `listing_id` ni estados de sincronización del primer cliente.
- [ ] URL, ID y token del webhook son propios del servidor B.
- [ ] Solo aparece un cliente al probar la API.
- [ ] Los cupos coinciden con la Oficina Virtual del segundo cliente.
- [ ] Se validó un inmueble manual antes de activar automatización.
- [ ] Nunca se ejecutó `POST /validate-listing` durante la instalación.
