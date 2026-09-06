# MÓDULO DE CUENTAS, ACCESOS Y ROLES
*Documento de Requerimientos y Diseño Técnico (Especificación de Software)*
**Código del Módulo: MOD_ACCESOS**  
**Versión: 1.0**  
**Proyecto: Sistema de Gestión Integral "Ferreterías El Constructor"**

---

## 1. Introducción y Contexto del Módulo
El presente documento describe detalladamente la especificación técnica y de requerimientos para el **Módulo de Cuentas, Accesos y Roles** del sistema transaccional de **Ferreterías El Constructor**.

Actualmente, la gestión descentralizada de las sucursales físicas genera serios inconvenientes de seguridad operacional, tales como la suplantación de identidades en cajas y muelle, así como la falta de auditoría en los ajustes de inventario. Este módulo establece el núcleo de seguridad, gobernanza de datos e identidad del sistema, garantizando que cada transacción operativa (ventas en POS, ajustes manuales de stock, pedidos a proveedores o traslados internos) esté firmemente asociada a una identidad digital verificada.

Para lograr esto de forma robusta e interactiva sin introducir la complejidad ni los pesados tiempos de descarga de frameworks JavaScript de cliente (como React o Vue), se ha elegido un enfoque moderno **"No-Build"**:
*   **Backend:** PHP Procedimental con acceso a datos tipado mediante **PDO** con modo de error por excepción para evitar inyecciones SQL de forma nativa.
*   **Frontend (Interactividad):** **HTMX**, que permite realizar peticiones AJAX asíncronas directamente desde atributos HTML, devolviendo fragmentos de HTML parciales del servidor que se insertan de forma fluida en el DOM del cliente sin recargar la página.
*   **Diseño Visual:** **Bulma CSS**, un framework CSS puro y responsivo que agiliza el diseño adaptable a terminales de caja, tablets y móviles.
*   **Motor de Base de Datos:** MariaDB y Microsoft SQL Server.

---

## 2. Requerimientos Funcionales
Los requerimientos funcionales modelan de forma detallada los servicios que el módulo de cuentas debe proveer:

| Código | Requerimiento Funcional | Descripción y Reglas de Negocio en el Sistema |
| :--- | :--- | :--- |
| **RF-01** | Registro y Autenticación Segura | El sistema debe proveer una interfaz de inicio de sesión (*login*) y recuperación de contraseña segura para todo el personal. No se permitirá el acceso a ninguna pantalla operativa del sistema sin una sesión activa y verificada. |
| **RF-02** | Control de Acceso Basado en Roles (RBAC) | Se deben configurar y validar estrictamente los siguientes roles de usuario con permisos mutuamente excluyentes:<br>• **Administrador:** Acceso completo al sistema, incluyendo auditoría global, respaldos, configuraciones fiscales y reportería estratégica.<br>• **Cajero:** Limitado estrictamente a la interfaz del punto de venta (POS), cobros, arqueos y asignación de clientes a ventas.<br>• **Bodeguero:** Acceso exclusivo a la recepción física de mercancía, muelle, gestión física de ubicaciones, control de lotes y despacho/recepción de traslados inter-sucursal.<br>• **Compras:** Gestión exclusiva de proveedores, costos pactados y automatización/aprobación de órdenes de compra. |
| **RF-03** | Vinculación del Usuario a Sucursal | Cada cuenta de usuario operativa (especialmente Cajeros y Bodegueros) debe estar vinculada obligatoriamente a una sucursal física activa del catálogo. El sistema limitará sus operaciones al inventario físico de dicha sucursal. |

---

## 3. Requerimientos No Funcionales
Los requerimientos no funcionales estipulan las restricciones técnicas, estándares informáticos e índices de calidad del módulo:

| Código | Requisitos No Funcionales | Métricas, Estándares e Integración Técnica |
| :--- | :--- | :--- |
| **RNF-01** | Cifrado de Contraseñas | Las contraseñas almacenadas deben ser encriptadas de manera inmutable utilizando el algoritmo de hash unidireccional seguro **bcrypt** (vía `password_hash()` de PHP con factor de costo predeterminado de 10). Queda prohibido el almacenamiento de contraseñas en texto plano o cifrados reversibles (MD5, SHA1). |
| **RNF-02** | Control de Sesiones y Expiración | El sistema debe gestionar sesiones de servidor seguras con tokens criptográficos de tiempo limitado. Las sesiones de los cajeros deben expirar automáticamente tras 20 minutos de inactividad para prevenir fraudes en cajas desatendidas. |
| **RNF-03** | Sanitización de Entradas | Todos los datos ingresados en el formulario de acceso y perfil deben ser estrictamente sanitizados y validados tanto en el lado del cliente (vía HTML5) como en el servidor (usando filtros nativos de PHP y sentencias preparadas de PDO) para anular intentos de inyección SQL y XSS. |

---

## 4. Bloques Funcionales (Complejidad de Desarrollo)
Para coordinar el cronograma de cara a la otra semana, el desarrollo de este módulo se divide en los siguientes bloques técnicos:

*   **Autenticación y Sesiones (Complejidad: Baja):** Desarrollo del login, recuperación de contraseñas y destrucción de sesiones de servidor. (*RF-01, RNF-02*)
*   **Gestión de Perfiles y RBAC (Complejidad: Media):** Pantallas de administración de usuarios, asignación de roles jerárquicos y vinculación de sucursales en base de datos. (*RF-02, RF-03*)
*   **Seguridad Transversal de Middleware (Complejidad: Alta):** Middleware de PHP que intercepta cada petición HTMX en el backend y valida si el token de sesión o rol activo posee los permisos correspondientes antes de devolver el fragmento HTML operativo. (*RNF-01, RNF-03*)

---

## 5. Diseño de Base de Datos (Modelo Relacional 3FN)
Para cumplir con la consistencia relacional de la cadena de ferreterías, se especifican las siguientes tablas normalizadas:

### Tabla: SUCURSAL
Define las tiendas físicas donde opera la cadena de ferreterías.
*   `id_sucursal` (INT, PK, AUTO_INCREMENT): Identificador único de la sucursal.
*   `nombre` (VARCHAR(100)): Nombre comercial de la tienda (ej. "Sucursal Norte", "Patio Central").
*   `direccion` (VARCHAR(255)): Dirección física de la sucursal.
*   `telefono` (VARCHAR(20)): Número telefónico de contacto.
*   `ciudad` (VARCHAR(100)): Ciudad de operación fiscal.
*   `estado_activo` (BOOLEAN): Estado de operación de la sucursal (1: Activa, 0: Cerrada/Inactiva).

### Tabla: USUARIO
Almacena las cuentas operativas del personal de la cadena.
*   `id_usuario` (INT, PK, AUTO_INCREMENT): Identificador único del usuario.
*   `id_sucursal` (INT, FK -> SUCURSAL.id_sucursal): Sucursal física principal asignada al usuario.
*   `username` (VARCHAR(50), UNIQUE): Nombre de usuario para acceso al sistema (único global).
*   `password_hash` (VARCHAR(255)): Hash bcrypt seguro de la contraseña.
*   `rol` (VARCHAR(30)): Rol asignado al usuario. Restringido mediante CHECK constraint (`'cajero'`, `'bodeguero'`, `'administrador'`, `'compras'`).
*   `estado_activo` (BOOLEAN): Estado del acceso (1: Activo, 0: Bloqueado/Desactivado).

### 5.1 Consideraciones de Diseño Clave
*   **Integridad Referencial:** Se prohíbe el borrado físico (`DELETE`) de usuarios o sucursales que tengan transacciones comerciales previas registradas. Se implementará un patrón técnico de **baja lógica** (`estado_activo = 0`) para preservar la trazabilidad e historial inalterable del sistema.
*   **Portabilidad Multi-Motor:** No se utilizará el tipo de datos nativo `ENUM` en la base de datos (ya que no es compatible de forma estándar entre MariaDB y SQL Server). En su lugar, se emplea `VARCHAR` acompañado de un **CHECK Constraint** a nivel de motor de base de datos para asegurar el dominio de valores de los roles.

---

## 6. Ciclo de Vida del Usuario en el Sistema
La cuenta de usuario de un empleado sigue un ciclo de estados inmutable controlado por el Administrador:

```
    [ Creado ] ──(Activación por Admin)──> [ Activo ]
                                              │    ▲
                  (Reintentos Fallidos de     │    │  (Desbloqueo
                   Contraseña > 5)            ▼    │   por Admin)
                                        [ Bloqueado ]
                                              │
                                        (Despido o Baja)
                                              ▼
                                        [ Inactivo ]
```

1.  **Creado:** La cuenta es pre-registrada por recursos humanos en la plataforma, pero permanece deshabilitada a la espera de que el empleado configure sus credenciales iniciales.
2.  **Activo:** El usuario puede iniciar sesión en la sucursal asignada y realizar las operaciones de su rol de manera fluida.
3.  **Bloqueado:** El sistema bloquea automáticamente la cuenta si se detectan más de 5 intentos fallidos de contraseña en un lapso de 10 minutos (prevención de ataques de fuerza bruta). Requiere desbloqueo manual del Administrador.
4.  **Inactivo:** Al finalizar la relación laboral, el usuario pasa a estado inactivo de forma permanente, invalidando sus tokens y accesos pero conservando su historial en bitácoras de auditoría de ventas y stock.

---

## 7. Arquitectura del Módulo (Bulma + HTMX)
El desarrollo del módulo sigue una convención estricta de 5 archivos específicos que estructuran la arquitectura limpia del proyecto:

1.  `cuentas_v.php` (Vista Partial): Formulario HTML estructurado con clases responsivas de Bulma. Emplea el atributo `hx-post="cuentas_c.php?action=login"` para enviar las credenciales asíncronamente y el atributo `hx-target="#login-response"` para volcar mensajes de error o redirecciones.
2.  `cuentas_c.php` (Controlador): Enruta las peticiones de HTMX. Valida si la acción solicitada es inicio de sesión, cierre de sesión o cambio de contraseña. Si las credenciales son válidas, inyecta la cabecera `HX-Redirect: dashboard.php` para la navegación rápida.
3.  `cuentas_l.php` (Lógica de Negocio): Contiene la lógica pura de autenticación. Llama a la validación de hash `password_verify($password, $user['password_hash'])` y configura las variables de sesión del servidor (`$_SESSION['id_usuario']`, `$_SESSION['rol']`, `$_SESSION['id_sucursal']`).
4.  `cuentas_d.php` (Datos / Acceso SQL): Ejecuta las consultas preparadas PDO contra MariaDB o SQL Server: `SELECT * FROM USUARIO WHERE username = :username AND estado_activo = 1`.
5.  `cuentas_m.php` (Mantenimiento): Script de migración y seeding que crea la tabla `USUARIO` e inyecta la cuenta inicial del Administrador de la cadena ferretera.

---

## 8. Puntos Pendientes de Definición
Antes del inicio formal del desarrollo la próxima semana, se deben definir con la gerencia los siguientes aspectos:
1.  **Autenticación de Dos Factores (2FA):** Definir si se integrará verificación obligatoria por correo electrónico o SMS para el perfil de Administrador general de la cadena ferretera.
2.  **Política de Contraseñas de Cajas:** Establecer si las contraseñas de los cajeros expiran y exigen cambio obligatorio cada 90 días calendario.
3.  **Usuarios Temporales:** Determinar si un empleado puede ser asignado temporalmente a más de una sucursal física en épocas de alta demanda de ventas.
