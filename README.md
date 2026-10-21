# phycode

phycode es un agente de código para la terminal. Trabaja sobre una carpeta de proyecto: lee y cambia archivos, busca en el código y ejecuta comandos. Pide permiso antes de escribir o ejecutar, salvo que lo cambies en la configuración.

Está pensado para modelos locales servidos con llama.cpp u Ollama. También funciona con OpenAI, Anthropic, OpenRouter, Groq y cualquier servidor compatible con la API de OpenAI.

Estado: versión 0.1.0, en fase temprana. Se ha probado con Qwen3.5 0.8B y 2B (Q8_0) sobre llama.cpp b11721. Los modelos pequeños siguen los protocolos de herramientas de forma irregular, así que revisa cada cambio que propongan.

## Qué hace

- Trabaja sobre una carpeta de proyecto: lee, busca, edita, crea, mueve y borra archivos, y ejecuta comandos de shell, Python y JavaScript.
- Usa git y GitHub (`gh`) con una lista blanca de operaciones. Las que escriben piden permiso.
- Delega en subagentes con contexto propio. Un subagente nunca tiene más herramientas ni menos restricciones que su padre.
- Puede ejecutar comandos en un sandbox: bubblewrap, Podman o Docker.
- Se conecta a servidores MCP por stdio o HTTP, con OAuth cuando el servidor lo pide.
- Busca en la web con siete backends y lee páginas con una guardia SSRF.
- Carga skills en formato `SKILL.md`, ejecuta hooks en los eventos del agente y lanza comprobaciones después de cada edición.
- Revisa cambios con cuatro revisores y un refutador (`/review`), investiga en varias rondas (`/deepresearch`) y responde preguntas laterales sin tocar la conversación (`/btw`).
- Compacta el contexto cuando se llena y guarda las sesiones para retomarlas.
- Funciona desde la terminal (chat de línea o pantalla completa), desde scripts (`run`, con salida JSON y JSON Schema), desde editores (ACP) y como demonio para varios clientes (`serve`).

## Requisitos

- PHP 8.4.1 o posterior, con las extensiones `curl`, `json`, `mbstring` y `posix`. `pcntl` es opcional: sin ella, Ctrl+C termina el proceso en lugar de cancelar el turno, y `serve` no arranca.
- Un servidor de modelos: `llama-server` (con `--jinja` para las llamadas nativas a herramientas), Ollama, un endpoint compatible con OpenAI, o un proveedor en la nube con su clave.
- Comandos del sistema: `timeout`, `bash` y `git`. Opcionales: `rg` (ripgrep, que usa `grep` si está instalado), `python3` y `node` (para `run_code`), y `gh` (para `gh_read` y `gh_write`).
- Go 1.26 o posterior, solo para compilar la interfaz de pantalla completa.
- Para sandboxes: `bwrap` (y `socat` si usas `sandbox.allow_domains`), o Podman o Docker.

## Instalación

```sh
composer install
chmod +x bin/phycode
bin/phycode doctor --cwd /ruta/al/proyecto
```

La interfaz de pantalla completa se compila una vez:

```sh
cd tui && go build -o ../bin/phycode-tui ./cmd/phycode-tui
```

Sin ese binario, `phycode -t` usa la interfaz de respaldo en PHP.

`bin/build-phar` empaqueta `src`, `resources` y `vendor` en `build/phycode.phar`, sin dependencias de desarrollo.

## Primeros pasos

Arranca un servidor local con un modelo GGUF:

```sh
llama-server -m Qwen3.5-2B-Q8_0.gguf --host 127.0.0.1 --port 8082 -c 16384 --jinja --alias qwen35-2b
```

Deja la caché KV en f16. Las cachés cuantizadas empeoran las llamadas a herramientas.

Crea `~/.config/phycode/config.json`:

```json
{
  "default_profile": "qwen-2b",
  "profiles": {
    "qwen-2b": {
      "base_url": "http://127.0.0.1:8082",
      "model": "qwen35-2b",
      "context_limit": 16384
    }
  }
}
```

Después, desde la carpeta del proyecto:

```sh
phycode doctor
phycode -t
phycode run "explica qué hace src/Agent/AgentRunner.php"
```

`doctor` comprueba el servidor, el perfil, el sandbox y las herramientas. `-t` abre la interfaz de pantalla completa. `run` ejecuta una tarea y termina.

## Configuración

Hay dos archivos de configuración:

- Global: `~/.config/phycode/config.json`, o `$XDG_CONFIG_HOME/phycode/config.json` si defines esa variable.
- Del proyecto: `<proyecto>/.phycode/config.json`. Solo puede definir `limits`, `default_agent` y `subagents`. No puede elegir servidores, claves, permisos ni sandbox.

Las variables `PHYCODE_BASE_URL` y `PHYCODE_MODEL` sustituyen los campos del perfil por defecto. `PHYCODE_PROFILE` elige el perfil por defecto. Si la configuración no define perfiles, esas dos variables crean un perfil llamado `env`, cuya clave sale de `PHYCODE_API_KEY`.

Secciones de primer nivel:

| Sección | Qué controla |
|---|---|
| `profiles`, `default_profile` | Servidores, modelos y opciones de cada perfil |
| `permissions` | Modo de permisos (`mode`) |
| `rules` | Reglas `allow`, `ask` y `deny` por herramienta |
| `sandbox` | Dónde corren `bash` y `run_code`, con su imagen y su red |
| `limits` | Turnos, tiempos y tamaños |
| `hooks`, `checks` | Comandos en eventos del agente y después de cada edición |
| `custom_tools` | Herramientas propias definidas por un comando |
| `tools` | `disabled`: herramientas que no se ofrecen |
| `mcp_servers`, `mcp` | Servidores MCP y búsqueda de herramientas |
| `web` | Búsqueda y lectura web |
| `skills` | `enabled`, `index` y `autonomy` de las skills |
| `context`, `effort` | Ventana, compactación automática y esfuerzo por defecto |
| `ui` | Tema, editor, vista de diferencias y expansión de subagentes |
| `pricing` | Precios por modelo, para `/usage` |
| `oracle` | Modelo más potente que el agente puede consultar |
| `ssh`, `deploy` | Hosts SSH y objetivos de despliegue |
| `gentle_ai` | Ruta del binario de gentle-ai y persona |

Claves de un perfil:

| Clave | Qué hace |
|---|---|
| `base_url` | Obligatoria. `http://` solo para `localhost`, `127.0.0.1` o `::1`. Con clave, `https://`. También acepta `unix:///ruta.sock`. |
| `model` | Obligatoria. Nombre del modelo en el servidor. |
| `protocol` | `openai` (por defecto), `openai-responses`, `anthropic` u `ollama`. |
| `tool_mode` | `auto` (por defecto), `native`, `text` o `none`. Ver Proveedores y modelos. |
| `thinking` | Esfuerzo de razonamiento inicial: `none` (por defecto), `minimal`, `low`, `medium`, `high`, `xhigh`, `max`, `off` u `on`. |
| `context_limit` | Ventana de contexto en tokens. Ollama la envía como `num_ctx`. |
| `max_output_tokens` | Tokens máximos de salida, entre 16 y 100000. Por defecto, 2048. |
| `token_param` | `max_tokens` o `max_completion_tokens`. Con `api.openai.com` se usa `max_completion_tokens`. |
| `api_key_env`, `api_key_file`, `api_key_store`, `api_key_files` | Dónde está la clave. Nunca va en línea. |
| `fallback` | Perfiles de respaldo, en orden. |
| `vision` | `true` si el modelo lee imágenes. Hace falta para `--image` y `/image`. |
| `keep_alive` | Solo Ollama. Por defecto, `5m`. |
| `extra_body` | Campos extra de la petición. Se añaden al final y pueden sobrescribir los demás. |
| `effort_format`, `effort_levels` | Cómo se envía el esfuerzo al proveedor. `effort_format` acepta `auto`, `openai`, `openrouter`, `deepseek`, `chat_template` y `none`. |
| `prompt_cache`, `prompt_cache_ttl`, `prompt_cache_retention` | Caché de prompts. TTL `5m` o `1h`. Retención `in_memory` o `24h`. |
| `compact_mode` | `auto` (por defecto), `shared` o `standalone`. |
| `llama` | Datos de un `llama-server` local: `bin`, `model`, `args` y `env`. |

Las claves nunca van en línea en el JSON. Usa `api_key_env` (una variable de entorno), `api_key_file` (un archivo) o `api_key_store: true` (la bóveda de phycode). `phycode keys set PERFIL` guarda una clave en la bóveda, en `keys.json` dentro de la carpeta de configuración.

Un perfil con clave solo puede usar `https://`, salvo que apunte a `localhost`, `127.0.0.1` o `::1`.

## Proveedores y modelos

| `protocol` | Para qué | Notas |
|---|---|---|
| `openai` | llama-server, LM Studio, vLLM, OpenRouter, Groq y OpenAI con `/v1/chat/completions` | Por defecto. Si el servidor rechaza herramientas junto con `reasoning_effort`, la petición pasa a `/v1/responses`, y esa ruta se guarda en la caché de capacidades del perfil. |
| `openai-responses` | API Responses de OpenAI | |
| `anthropic` | API Messages de Anthropic | Admite caché de prompts. |
| `ollama` | API nativa de Ollama (`/api/chat`) | Admite `keep_alive`. Solo está probado con fixtures, porque no había un modelo de chat instalado. |

Los presets (`src/Config/ProviderPresets.php`) fijan la URL base y la variable de clave de siete proveedores: `openai`, `anthropic`, `openrouter`, `groq`, `lmstudio`, `vllm` y `ollama-cloud`.

`fallback` indica perfiles de respaldo, en orden. Si el perfil principal falla, phycode prueba el siguiente.

`tool_mode` decide cómo se llaman las herramientas:

- `auto` (por defecto) sondea el servidor una vez y guarda el resultado. Los errores transitorios no se guardan, así que un servidor corregido funciona en la siguiente ejecución.
- `native` usa los campos `tools` y `tool_calls` de la API.
- `text` pide al modelo bloques `<tool_call>` y lee los resultados en bloques `<tool_response>`.
- `none` desactiva las herramientas.

El esfuerzo de razonamiento se cambia con `/effort`: `none`, `minimal`, `low`, `medium`, `high`, `xhigh` o `max`. Cada proveedor acepta un subconjunto, y phycode traduce el nivel a su formato (`src/Llm/EffortMapping.php`). `off` equivale a `none`, y `on` a `medium`.

`phycode doctor` comprueba un servidor llama.cpp local: sus propiedades, sus slots y sus modelos. Si el modelo ya está cargado, también prueba las llamadas a herramientas. Admite sockets Unix, TLS y clave de API.

## Uso

### Comandos de la CLI

| Comando | Qué hace |
|---|---|
| `phycode run "tarea"` | Ejecuta una tarea y termina. `-p` es un alias. |
| `phycode chat` | Chat de línea en la terminal. |
| `phycode -t` | Interfaz de pantalla completa. Es la misma sesión que `chat`. |
| `phycode rpc` | Protocolo de JSON por líneas en stdin y stdout. Lo usa la interfaz de pantalla completa. |
| `phycode acp` | Agente para editores que hablan Agent Client Protocol (Zed, JetBrains, Neovim). |
| `phycode serve` | Demonio que atiende a varios clientes por un socket Unix, o por un puerto de loopback con token. |
| `phycode agents` | Lista los agentes con sus herramientas y restricciones. |
| `phycode doctor` | Comprueba el servidor, el perfil, el sandbox y las herramientas. |
| `phycode keys` | Guarda, lista o borra claves de API de un perfil (`set`, `list`, `rm`). |
| `phycode shell` | Muestra, instala o quita el bloque de PATH en tu shell de inicio (`status`, `install`, `uninstall`). |
| `phycode ssh` | Hosts SSH donde el modelo puede ejecutar comandos (`list`, `add`, `remove`, `trust`, `test`). |
| `phycode mcp` | Servidores MCP (`list`, `login`, `logout`, `test`). |
| `phycode deploy` | Objetivos de despliegue (`list`, `add`, `remove`, `secret`, `plan`, `history`). |
| `phycode version` | Muestra la versión. |

Opciones comunes:

- `--cwd DIR`, `--profile NOMBRE`, `--agent NOMBRE`.
- `--sandbox NOMBRE`, con `host`, `podman`, `docker` o `bwrap`. Gana a la configuración y a los agentes.
- `--yolo`, para no preguntar antes de escribir ni de ejecutar.
- `--add-dir DIR`, una carpeta de solo lectura fuera del proyecto. `read`, `list` y `grep` pueden usarla. Mientras esté puesta, no se ofrecen las herramientas de shell (`bash`, `job`, `run_code`) ni las remotas (`ssh`, `ssh_copy`, `deploy`, `deploy_info`).

Opciones de `run`:

- `--output-format text`, `json` o `stream-json`, o `--json` como atajo.
- `--model NOMBRE` y `--max-turns N`.
- `--output-schema S`: un archivo o un JSON con un JSON Schema.
- `--image ARCHIVO`, repetible. El perfil necesita `vision: true`.
- `-c` o `--continue` retoma la sesión más reciente del proyecto. `-r ID` o `--resume ID` retoma una sesión concreta.

### Pantalla completa

- Enter envía. Alt+Enter añade una línea.
- Si el agente está trabajando, Enter pone el mensaje en cola. Alt+Up devuelve la cola al editor.
- Esc interrumpe el turno, y Ctrl+C también lo detiene. Sin turno en marcha, Ctrl+C limpia el editor, o sale si ya está vacío. Ctrl+D sale con el editor vacío.
- PgUp y PgDn desplazan la transcripción.
- Cuando una herramienta pide aprobación: `y` permite una vez, `a` permite esa herramienta durante la sesión, `n` la niega, y Esc niega solo esa llamada.
- Escribe `/` para ver los comandos, y `@` para ver los subagentes.
- `/models` abre el selector de modelos. Enter lo usa en la sesión. Ctrl+S lo guarda además como predeterminado de su perfil, y Ctrl+F lo marca como favorito.

### Comandos de chat

| Comando | Qué hace |
|---|---|
| `/models` | Lista los modelos y cambia el de esta sesión. |
| `/effort [NIVEL]` | Muestra o cambia el esfuerzo de razonamiento del siguiente turno. |
| `/context` | Muestra el contexto usado, el umbral de compactación y los tokens por categoría. |
| `/context max` | Fija la ventana de referencia del umbral: un número, un número con `k`, o `off`. Con `--save`, lo guarda en la configuración. |
| `/compact [INSTRUCCIONES]` | Resume los turnos antiguos con el modelo y conserva el último cuarto de la ventana. |
| `/usage` | Tokens gastados en la sesión, por modelo, y su coste si la configuración tiene precios. |
| `/status` | Versión, perfil, modelo, sandbox, permisos y transcripción. |
| `/sessions` | Lista las sesiones guardadas. `/sessions switch ID` cambia a una, y `/sessions new` empieza otra. |
| `/clear` | Empieza una conversación nueva. |
| `/handoff` | Escribe un resumen de la sesión para continuarla en otra. Se guarda en `.phycode/handoffs`. |
| `/diff` | Muestra los cambios del último turno que tocó archivos. |
| `/undo`, `/redo` | Devuelven o vuelven a aplicar los archivos del último turno. Pide confirmación. |
| `/rewind TURNO` | Devuelve los archivos a como estaban antes de un turno, junto con los posteriores. |
| `/agent NOMBRE`, `/agents` | Cambia el agente principal, o lista los agentes. |
| `/plan [TAREA]` | Cambia al agente `plan`. Apruebas el plan antes de que se construya. |
| `/todo [clear]` | Muestra o borra la lista de tareas de la sesión. |
| `/image RUTA [TEXTO]` | Envía una imagen con una pregunta. El perfil necesita `vision: true`. |
| `/skill [NOMBRE]`, `/skills` | Lista o muestra skills. `/skills` lista las de gentle-ai, en solo lectura. |
| `/mcp` | Lista los servidores MCP y sus herramientas. |
| `/init` | Explora el proyecto y escribe `AGENTS.md`, con tu aprobación. |
| `/review [base-ref]` | Revisa tus cambios con cuatro revisores, un refutador y el comando de tests. No edita nada. |
| `/review mode` | Activa (`on`), desactiva (`off`) o muestra (`status`) las revisiones. |
| `/deepresearch PREGUNTA` | Investiga en la web en hasta cinco rondas y responde con fuentes numeradas. |
| `/btw PREGUNTA` | Responde una pregunta lateral sin herramientas, en otro proceso. No altera la conversación. |
| `/gentle` | Muestra el estado de gentle-ai, o ejecuta sus comandos de instalación, sincronización y actualización. Cada uno pide aprobación. |
| `/mcp preset` | Añade a la configuración global un servidor MCP de gentle-ai: `engram`, `context7` o `codegraph`. |
| `/persona` | Cambia la persona de las respuestas del chat: `gentleman`, `neutral` u `off`. No afecta al código. |
| `/shell` | Muestra, instala o quita el bloque de PATH en tu shell. |
| `/odd FEATURE` | Crea `odd/tasks/FEATURE.md` a partir de la plantilla ODD. |
| `/reload` | Vuelve a leer la configuración sin reiniciar. |
| `/help`, `/exit` | Muestra los comandos o sale. `/quit` también sale. |

Además, `@NOMBRE TAREA` lanza un subagente directo y añade su informe a la conversación.

### Salida para scripts

- `run` imprime el texto final en stdout. El resumen de cambios, la transcripción y la sesión van a stderr.
- `--output-format json` imprime un solo objeto con `status`, `text`, `turns`, `tool_calls`, `reason`, `changed`, `transcript`, `session_id`, `last_usage` y `usage`.
- `--output-format stream-json` imprime un evento por línea.
- Con `--output-schema`, la respuesta final debe ser un JSON que cumpla el esquema. Si no lo cumple, phycode lo vuelve a pedir hasta dos veces. Si sigue sin cumplirlo, sale con código 4.
- Códigos de salida: `0` completado; `2` detenido, cancelado o con error; `3` presupuesto de turnos agotado; `4` respuesta fuera de esquema.

## Permisos

El modo se fija con `permissions.mode` en la configuración global, o con `--yolo`.

| Modo | Qué pasa |
|---|---|
| `ask` (por defecto) | Pide antes de escribir archivos, ejecutar comandos y hacer operaciones de git que escriben o hablan con un remoto. |
| `accept_edits` | Escribe archivos sin preguntar. Los comandos siguen preguntando. |
| `plan` | Solo permite herramientas de lectura. |
| `dont_ask` | No pregunta. Lo que necesitaría aprobación se rechaza. |
| `yolo` | No pregunta, salvo en las acciones que lo fuerzan (ver abajo). |

- Estas acciones piden aprobación en todos los modos, incluido `yolo`: leer, buscar, escribir, editar, mover o borrar archivos con nombre de secreto, como `.env`, `.envrc`, `terraform.tfvars`, `.kube/config`, `.docker/config.json` o el `keys.json` de la bóveda. Algunos casos de `ssh`, `ssh_copy`, `deploy` y `web_fetch` también piden aprobación en todos los modos.
- Sin terminal, por ejemplo con `run` en un script o con `chat` alimentado por una tubería, las escrituras y los comandos quedan desactivados. `--yolo` los vuelve a permitir.
- Las herramientas no escriben en `.git`, `.phycode` ni `.claude`, a ninguna profundidad. Mover o borrar una carpeta que contenga una de ellas también se rechaza.
- Los enlaces simbólicos se siguen para comprobar que su destino está dentro del proyecto. Si sale del proyecto, se rechaza.
- `rules` tiene las listas `allow`, `ask` y `deny`, con entradas `herramienta:patrón` o `*`. Por ejemplo, `"bash:git status *"` o `"bash:npm test"`. Un comando encadenado solo se permite si todos sus segmentos lo están, y nunca se permite `git -c`.
- El orden de decisión es: reglas `deny`, línea dura, restricciones del agente, modo de permisos, reglas `ask` y `allow`, y por último tu respuesta.

La línea dura es una lista de comandos que nunca se ejecutan, en ningún modo y con ninguna aprobación. Cubre el borrado recursivo de rutas del sistema y las variantes que el shell expande de otra forma, como `rm -rf /`, `find / -delete` o `wipefs` sobre un disco. Dentro de Podman o Docker, `bash` no pasa por la línea dura, porque el contenedor es el límite. La línea dura tampoco lee el código de `run_code`.

Las claves configuradas se ocultan en toda la salida, incluida la de las herramientas y las sesiones guardadas. Además, los mensajes de error, los avisos, la lista de modelos y `doctor` ocultan URLs con usuario y contraseña, cabeceras `Authorization` y claves con formato `sk-`. Las secuencias de escape de terminal se eliminan antes de mostrar nada.

## Sandbox

- `sandbox.default` elige dónde corren `bash` y `run_code`: `host` (por defecto), `bwrap`, `podman` o `docker`. `--sandbox` lo sustituye en esa ejecución.
- Un agente puede pedir otro sandbox en su archivo, pero no puede pasar a `host` si la configuración usa uno.
- Podman y Docker necesitan `sandbox.image`, por ejemplo `docker.io/library/debian:trixie-slim`.
- `sandbox.network` es `none` por defecto, o `bridge`.
- `sandbox.allow_domains` lista los hosts permitidos, como `github.com` o `*.npmjs.org`. Solo funciona con `bwrap` y con red `none`, y necesita `socat`.

Con bubblewrap, el sistema de archivos se monta en solo lectura y el proyecto en escritura. Las rutas con credenciales habituales (`~/.ssh`, `~/.aws`, `~/.gnupg`, `~/.kube`, `~/.config/gh` y `~/.config/phycode`, entre otras) se ocultan con un tmpfs o con un archivo vacío.

Con Podman o Docker, el proyecto se monta en `/workspace`, y `.git`, `.phycode` y `.claude` se montan en solo lectura. Se quitan todas las capacidades, se activa `no-new-privileges`, hay un límite de 2 GB de memoria y 512 procesos, y `/tmp` es un tmpfs de 512 MB. La imagen nunca se descarga sola (`--pull=never`).

Las herramientas git no pasan por el sandbox. Se ejecutan en el host, así que las credenciales y el socket del agente SSH no entran en el contenedor.

El sandbox protege la máquina fuera del proyecto. No protege el proyecto: un comando puede cambiar cualquier archivo de él, y lo que se ejecute después en el host, como un build o los tests, ejecuta ese código.

## Herramientas

Las herramientas se ofrecen según el agente, según `tools.disabled` y según las funciones activas. Las de `web` solo aparecen si hay sección `web`. `skill` y `skill_manage` solo aparecen si las skills están activas. `ssh` y `deploy` solo aparecen si hay hosts u objetivos definidos.

| Herramienta | Categoría | Qué hace | Aprobación en `ask` |
|---|---|---|---|
| `read` | lectura | Lee un archivo. Por defecto devuelve hasta 200 líneas (`limits.read_max_lines`). | No, salvo archivos secretos |
| `list` | lectura | Lista los archivos del proyecto. En un repositorio usa `git ls-files`. | No |
| `grep` | lectura | Busca texto. Usa `rg` si está instalado. | No, salvo rutas secretas |
| `repo_map` | lectura | Mapa de clases y funciones con su línea. Lenguajes: PHP, JavaScript y TypeScript, Python, Go, Rust, Java, Kotlin, Scala, C#, Ruby, C y C++. | No |
| `write` | escritura | Crea o reemplaza un archivo. Si es PHP o Python y no compila, el cambio se revierte. | Sí |
| `edit` | escritura | Reemplaza un texto dentro de un archivo, con la misma comprobación de sintaxis. | Sí |
| `move`, `delete` | escritura | Mueve o borra. Lo borrado va a `.phycode/trash`. | Sí |
| `bash` | ejecución | Ejecuta un comando de shell en el sandbox elegido. Con `background: true`, corre en segundo plano. | Sí |
| `job` | lectura | Lee la salida de un comando en segundo plano, o lo detiene. | No |
| `run_code` | ejecución | Ejecuta un programa corto de Python o JavaScript en la raíz del proyecto. La aprobación muestra el programa completo. | Sí |
| `git_read` | lectura | `status`, `diff`, `log`, `show`, `blame`, y listados de ramas, etiquetas y remotos. | No |
| `git_write` | escritura | `add`, `commit -m`, crear y borrar ramas, `switch`, etiquetas, `stash`, `merge`, `reset` en modo `soft` o `mixed`, y `restore --staged`. | Sí |
| `git_remote` | ejecución | `fetch`, `pull --ff-only` y `push`. Nunca fuerza ni borra ramas remotas. | Sí |
| `gh_read`, `gh_write` | lectura y ejecución | Lectura y escritura en GitHub con `gh`. | No, y sí |
| `task` | delegación | Lanza un subagente. Solo si los subagentes están activados. | No |
| `todo` | lectura | Lista de tareas de la sesión. | No |
| `web_search` | lectura | Busca en la web con el backend activo. | No |
| `web_fetch` | lectura | Descarga una página y la devuelve como texto. | Sí, si la URL trae consulta (`?...`), usuario, o una ruta de más de 300 caracteres |
| `skill`, `skill_manage` | lectura y escritura | Carga una skill, o crea y modifica skills del usuario. | `skill_manage` pide, salvo con `skills.autonomy: "auto"` |
| `ask_user` | lectura | Hace una pregunta al usuario. Solo en modo interactivo. | No aplica |
| `oracle` | ejecución | Consulta un modelo más potente, definido en `oracle`. | Sí, salvo con `oracle.approval: "allow"` |
| `ssh`, `ssh_copy` | ejecución | Comandos y copias en los hosts de `ssh.hosts`. | Sí |
| `deploy`, `deploy_info` | ejecución y lectura | Despliegues a los objetivos de `deploy.targets`. `deploy_info` consulta información de esos objetivos. | `deploy` sí |
| Herramientas propias (`custom_tools`) | ejecución | Ejecutan un comando que defines en la configuración. | Sí |

Con `accept_edits`, las herramientas de escritura no piden. Con `yolo`, solo piden las acciones forzadas. Con `plan`, solo se permiten las de lectura. Un agente de solo lectura (`permission: readonly`) no recibe herramientas de escritura ni de ejecución, en ningún modo.

## Agentes y subagentes

Un agente es un archivo Markdown con frontmatter YAML. El cuerpo del archivo es su prompt de sistema.

| Agente | Modo | Herramientas | Notas |
|---|---|---|---|
| `build` | principal | Todas | Agente por defecto. Responde sin herramientas cuando la petición no requiere trabajar en el proyecto. |
| `plan` | principal | `read`, `list`, `grep`, `task` | Solo lectura. Escribe un plan con pasos que otro agente puede ejecutar sin haber visto la investigación. |
| `explore` | subagente | `read`, `list`, `grep` | Solo lectura. Como máximo 8 turnos. Pide un informe de hasta 200 palabras. |
| `general` | subagente | `read`, `list`, `grep`, `write`, `edit`, `move`, `delete`, `bash`, `run_code` | Como máximo 12 turnos. No puede delegar. |

Los revisores de `/review` son `reviewer-readability`, `reviewer-reliability`, `reviewer-resilience`, `reviewer-risk` y `reviewer-refuter`.

Los agentes propios se buscan en este orden:

1. `.phycode/agents` y `.claude/agents` del proyecto, desde la carpeta de trabajo hasta la raíz.
2. `~/.config/phycode/agents`.
3. `~/.claude/agents`.
4. Los integrados.

Campos del frontmatter:

- `name` y `description`, obligatorios.
- `mode`: `primary`, `subagent` o `all`. Por defecto, `subagent`.
- `tools` y `disallowedTools`: listas de herramientas. Los nombres de Claude Code (`Read`, `Grep`, `Glob`, `Bash`) se traducen.
- `permission: readonly` o `permissionMode: plan`: limitan el agente a la lectura.
- `model` o `profile`: solo en la configuración global. En un archivo del proyecto se ignoran con un aviso.
- `max_turns` (también `maxTurns` o `steps`), de 1 a 100.
- `temperature`, de 0 a 2.
- `sandbox`: un agente puede elegir otro sandbox, pero no puede pasar a `host` si la configuración usa uno.
- `disable: true`: oculta el agente.

Reglas de delegación:

- Un subagente empieza con una conversación nueva: su prompt y la tarea. Solo vuelve su informe final.
- Solo hay un nivel: un subagente no puede lanzar otro.
- Un subagente nunca tiene más herramientas que su padre, ni menos restricciones. Los modos de permisos se heredan, y el sandbox no se puede debilitar.
- Cada subagente tiene su presupuesto de turnos y un tiempo máximo de `limits.subagent_timeout` (300 segundos por defecto). Si se agota el presupuesto, se le pide un informe final sin herramientas.
- Una respuesta puede lanzar hasta 3 tareas (`max_tasks_per_turn`), y una ejecución, hasta 10 (`max_tasks_per_run`).
- Las llamadas a herramientas de una misma respuesta se ejecutan en orden, una después de otra.

## Skills

- Una skill es una carpeta con un `SKILL.md`. Su frontmatter tiene `name` y `description`.
- phycode busca skills en `.agents/skills` y `.claude/skills` del proyecto, en `~/.agents/skills` y `~/.claude/skills`, y en `~/.config/phycode/skills`, donde el agente guarda las suyas.
- Si `skills.index` está en `on` (por defecto), el índice de skills entra en el prompt. El texto completo de una skill se carga solo cuando hace falta, con la herramienta `skill`.
- `skill_manage` crea, parchea o escribe archivos de skills del usuario. Antes de guardar, escanea el contenido (`SkillScanner`). Pide aprobación, salvo que pongas `skills.autonomy` en `"auto"`.

## MCP

- Los servidores se definen en `mcp_servers`. Un servidor es un `command` (por stdio: el programa y sus argumentos), o una `url` (HTTP, con `headers`, `token_env` u `oauth`).
- `enabled: false` apaga un servidor sin borrarlo. `approval` vale `ask` (por defecto) o `allow`. Con `allow`, phycode confía en las herramientas de ese servidor.
- Las cabeceras con nombres como `Authorization`, `api-key` o `token` se rechazan en `config.json`. Para credenciales, usa `token_env` u `oauth`.
- Las herramientas aparecen como `mcp__SERVIDOR__HERRAMIENTA`.
- `mcp.tool_search` controla la búsqueda de herramientas. `auto` (por defecto) la activa cuando hay más de 20 herramientas o más de 30 000 bytes de esquemas. También acepta `on` y `off`.
- Los recursos y los prompts del servidor se exponen si el servidor los ofrece.
- Comandos: `phycode mcp list`, `login NOMBRE`, `logout NOMBRE` y `test NOMBRE`.

## Web

- `web_search` usa el backend activo de `web.search`: `searxng`, `brave`, `tavily`, `exa`, `perplexity`, `ollama` o `firecrawl`. Cada backend se define como proveedor con nombre en `web.search.providers`, y `web.search.active` elige cuál usar. La clave va al almacén (`api_key_store`) o a un archivo (`api_key_file`).
- `web_fetch` descarga una página y la devuelve como texto. Por defecto, el límite es de 1 MiB, el tiempo de espera de 20 segundos y la caché de 15 minutos (`web.fetch.max_bytes`, `web.fetch.timeout_seconds` y `web.cache_minutes`).
- `web.allow_hosts` lista los hosts permitidos, por nombre o con `*.dominio`.
- Antes de conectar, la guardia SSRF valida el destino y fija las direcciones comprobadas. El contenido de la web entra al contexto marcado como no confiable.

## Hooks, comprobaciones y herramientas propias

- `hooks` tiene cinco eventos con los mismos nombres que en Claude Code: `PreToolUse`, `PostToolUse`, `UserPromptSubmit`, `Stop` y `SubagentStop`. Cada entrada tiene `command`, `match` (patrones separados por `|` o `,`) y `timeout` (de 1 a 600 segundos, 10 por defecto). El comando recibe el evento en JSON por stdin.
- `PreToolUse` puede bloquear una llamada o cambiar sus argumentos. `UserPromptSubmit` puede bloquear un mensaje o añadirle contexto. `PostToolUse` añade notas al resultado.
- `Stop` y `SubagentStop` pueden pedir que el agente siga trabajando, hasta 8 veces por ejecución.
- `checks` ejecuta comandos después de cada `edit` o `write` exitoso sobre los archivos que coinciden con `match`. `{file}` se sustituye por la ruta. Las comprobaciones de `kind: "format"` corren primero y pueden reescribir el archivo. El tiempo máximo por defecto es de 30 segundos.
- `custom_tools` define herramientas propias con `name`, `description`, `command` y `stdin`. Piden aprobación como cualquier comando.
- `tools.disabled` desactiva herramientas por nombre.

## Sesiones, contexto y compactación

- Cada ejecución guarda una transcripción en formato JSON Lines dentro de `.phycode/sessions/`, y una sesión que puedes retomar con `-c`, `-r ID`, `/sessions` o `/sessions switch ID`.
- phycode escribe un `.gitignore` con `*` dentro de `.phycode`, así que git ignora todo lo que escribe el agente.
- `AGENTS.md` o `CLAUDE.md` del proyecto, y los del usuario en `~/.config/phycode/`, se añaden al prompt de sistema. Se recortan a `limits.agents_md_bytes`, 6144 bytes por defecto. `/init` escribe `AGENTS.md` después de tu aprobación.
- La compactación automática empieza cuando el contexto llega a la ventana menos un colchón. La ventana es `context.max` si la fijas, o la del modelo en otro caso. El colchón es `context.buffer`, o el mayor entre 200 tokens y el 2 % de la ventana. phycode resume los turnos antiguos con el modelo y conserva el último cuarto de la ventana tal cual. `context.auto_compact: false` la desactiva. `/compact` la lanza a mano.
- `/context max N` (o `Nk`, u `off`) fija la ventana de referencia. `off` usa la del modelo. Con `--save`, lo guarda en la configuración.
- Cada turno que cambia archivos guarda copias previas. `/diff`, `/undo`, `/redo` y `/rewind TURNO` las usan.

## Integraciones

- ACP: `phycode acp` habla Agent Client Protocol por stdin y stdout, en la versión 1. Ofrece los modos de permisos Ask, Accept edits, Plan y Do not ask.
- RPC: `phycode rpc` usa JSON por líneas en stdin y stdout. Es el backend de `phycode -t`.
- Serve: `phycode serve` es un demonio con varios clientes. Escucha en un socket Unix (por defecto, `$XDG_RUNTIME_DIR/phycode/serve.sock`, o `/tmp/phycode-UID/serve.sock`, en una carpeta tuya con modo 0700), o en `--listen 127.0.0.1:PUERTO` con un token (`--token-file`). Cada cliente envía primero `{"type":"hello","token":"...","cwd":"/ruta"}`. Quien se conecta usa tus herramientas, así que protege el socket y el token.
- SSH y despliegues: los hosts van en `ssh.hosts` y los objetivos en `deploy.targets`. Los objetivos pueden usar Cloudflare, Vercel, Docker, Modal, SSH o un comando propio. Comandos: `phycode ssh` y `phycode deploy`.
- gentle-ai: herramienta externa (MIT) que instala y mantiene skills, personas y presets de MCP para agentes de código. `/gentle` muestra su estado y ejecuta sus comandos después de tu aprobación. `gentle_ai.bin` fija la ruta del binario, y `/persona` cambia la persona de las respuestas del chat.

## Arquitectura

| Carpeta | Responsabilidad |
|---|---|
| `src/Agent` | Bucle del agente (`AgentRunner`), políticas de herramientas, subagentes, hooks, comprobaciones y definición de agentes. |
| `src/Cli` | Arranque, comandos de la CLI, cableado (`Kernel`), chat y comandos de chat. |
| `src/Config` | Carga y validación de `config.json`, perfiles, sandbox, límites y presets de proveedores y de búsqueda. |
| `src/Llm` | Clientes de `openai`, `openai-responses`, `anthropic` y `ollama`; reintentos, SSE, caché de capacidades, precios y esfuerzo. |
| `src/Tools` | Las herramientas que ve el modelo, y su registro. |
| `src/Security` | Permisos, reglas, línea dura, rutas seguras, archivos secretos y análisis de comandos. |
| `src/Exec` | Ejecución en el host, con bwrap, Podman y Docker. Filtro de dominios y procesos en segundo plano. |
| `src/Git` | Políticas de `git` y `gh`. |
| `src/Mcp` | Cliente MCP (stdio y HTTP), OAuth y búsqueda de herramientas. |
| `src/Skills` | Catálogo, escaneo y escritura de skills. |
| `src/Web` | Búsqueda con siete backends, lectura de páginas, guardia SSRF y caché. |
| `src/Review`, `src/Research`, `src/Btw` | `/review`, `/deepresearch` y `/btw`. |
| `src/Checkpoint` | Deshacer, rehacer y rebobinar por turno. |
| `src/Session` | Sesiones, transcripciones, compactación y presupuesto de contexto. |
| `src/Context` | Carga de `AGENTS.md` y `CLAUDE.md`. |
| `src/Rpc`, `src/Acp`, `src/Serve` | Protocolos para la interfaz, los editores y los clientes remotos. |
| `src/Tui`, `src/Ui` | Interfaz de respaldo en PHP, y salidas (consola y `stream-json`). |
| `src/Ssh`, `src/Deploy` | Hosts SSH y despliegues, con sus drivers. |
| `src/Gentle` | Integración con gentle-ai. |
| `src/RepoMap` | Mapa de símbolos del código. |
| `src/Memory` | Notas persistentes. Aún no están conectadas (ver Limitaciones). |
| `src/Diff`, `src/Support` | Diferencias, bóveda de claves, redacción, JSON y estado. |
| `tui/` | Interfaz de pantalla completa en Go, con Bubble Tea, Lip Gloss y Glamour. |
| `resources/` | Agentes integrados y personas. |
| `docs/security/` | Auditoría de seguridad del 21 de octubre de 2026. |

## Desarrollo y pruebas

```sh
vendor/bin/phpunit
(cd tui && go test -race ./...)
```

- Los tests unitarios usan respuestas simuladas del modelo, repositorios git temporales y un runtime de contenedores falso. No necesitan un servidor de modelos ni un demonio de contenedores.
- Los tests que necesitan `bwrap` se omiten si no está instalado.
- La auditoría del 21 de octubre de 2026 registra 4 544 tests unitarios y 21 de integración, y todos pasan. Los tests de Go también pasan, y `go test -race` pasa en `backend`, `app` y `view`.
- Los fixtures grabados están en `tests/Fixtures`: respuestas de llama-server (b11721) y de otros proveedores.

## Seguridad

El modelo se trata como una fuente no confiable. Puede recibir instrucciones inyectadas desde páginas web, archivos del repositorio, respuestas de MCP o skills. `docs/security/AUDIT-2026-10-21.md` describe el modelo de amenazas, los hallazgos corregidos y los riesgos residuales.

Los permisos `ask` no son un límite de seguridad. Para los comandos de shell, el límite real es un sandbox de contenedor.

Actualiza PHP antes de usar phycode. La auditoría indica que PHP 8.4.26 es anterior al parche del 21 de octubre de 2026 para CVE-2026-109618 y CVE-2026-109619.

## Limitaciones conocidas

- Ollama solo está probado con fixtures, porque no había un modelo de chat instalado para probarlo en vivo.
- Las notas persistentes (`src/Memory/MemoryStore.php`) tienen límites por ámbito y rechazan secretos, pero no están conectadas a ninguna herramienta ni al prompt.
- `run_code` no pasa por la línea dura. Su protección es el sandbox.
- Un archivo secreto renombrado a un nombre que no lo parece puede leerse por ese nombre nuevo, una vez aprobado el movimiento. La aprobación muestra los dos nombres.
- Con respuestas muy largas (unos 120 KB), la interfaz de Go consume bastante CPU mientras la respuesta se transmite, porque renderiza el markdown en cada fragmento. La auditoría lo registra como G-1 y no está resuelto.

## Frente a otros agentes

Esta tabla resume lo que cada proyecto declara en su README y en su documentación dentro del repositorio, con fecha de revisión del 21 de octubre de 2026. Para escribirla no se leyó el código de los otros proyectos.

| | phycode | pi | Hermes Agent | OpenCode | Claude Code | Codex |
|---|---|---|---|---|---|---|
| Licencia | Propietaria | MIT | MIT | MIT | Todos los derechos reservados. El CLI no está en el repositorio público | Apache-2.0 |
| Lenguaje | PHP 8.4 y Go | TypeScript | Python | TypeScript | Solo plugins, ejemplos y scripts en el repositorio público | Rust, con SDK en TypeScript y Python |
| Modelos | llama.cpp, Ollama, OpenAI, Anthropic, OpenRouter, Groq y compatibles | Varios proveedores (`pi-ai`) | Varios, incluido Nous Portal y OpenRouter | No detallado en su README | Modelos Claude | Cuenta de ChatGPT o clave de API de OpenAI |
| Permisos y sandbox | Cinco modos, reglas, línea dura, sandbox con bwrap, Podman o Docker | Sin sistema de permisos. Recomienda contenedores | Modos `smart`, `manual` y `off`. Siete backends de terminal | Agente `plan` sin edición, y bash con permiso | Modos de permisos. CI con firewall de salida | Documentación en la web de OpenAI |
| Subagentes | Un nivel, contexto propio, permisos heredados | Sin subagentes, por diseño | `delegate_task`, hasta 10 en paralelo por defecto | Subagente `general`, invocable con `@general` | Agentes dentro de plugins | No descritos en el repositorio |
| Skills y memoria | `SKILL.md`, `AGENTS.md` y sesiones. Las notas persistentes aún no están conectadas | Skills con la especificación Agent Skills. Sesiones en árbol con ramas | Skills que el agente crea y mejora. `MEMORY.md` y `USER.md` | No descritas en su README | Skills y comandos dentro de plugins | `AGENTS.md`. Resto en la web de OpenAI |
| Extensiones | Hooks (cinco eventos), comprobaciones, herramientas propias y MCP | Extensiones TypeScript en el proceso, paquetes npm o git, MCP y codemode | Cuatro sistemas de hooks, plugins y MCP | API de plugins en `packages/plugin` | Plugins con comandos, agentes, hooks y MCP | Hooks gestionados por administradores. Resto en la web |
| Interfaces | Chat, pantalla completa en Go, `run`, RPC, ACP y `serve` | Pantalla completa, modo impresión y JSON, RPC y SDK | CLI, gateways de mensajería, escritorio y cron | Terminal y app de escritorio (beta) | Terminal, IDE y `@claude` en GitHub | CLI, extensión de IDE, app de escritorio y web |

## Licencia

Propietaria. `composer.json` la declara como `proprietary`, y el repositorio no incluye un archivo LICENSE.
