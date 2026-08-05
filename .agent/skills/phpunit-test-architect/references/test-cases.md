# Casos de evaluación de la skill

Usar estos escenarios al modificar o validar la skill. No forman parte del formato de salida normal.

## Caso 1 — Inicialización sin especificación

**Given:** El usuario invoca la skill sin aportar dominio, interfaz, firma, reglas ni requisito.

**When:** La skill responde por primera vez.

**Then:** La salida contiene exactamente una oración de reconocimiento, no contiene Markdown ni genera pruebas.

## Caso 2 — Contrato completo sin supuestos

**Given:** El usuario proporciona namespace, interfaz pública, firmas, reglas de negocio, errores y límites completos.

**When:** La skill diseña la suite.

**Then:** La salida omite `## 1. Assumptions`, incluye `## 2. Architectural Overview` y `## 3. PHPUnit Test Code`, y no contiene texto posterior al código.

## Caso 3 — Especificación incompleta pero utilizable

**Given:** El usuario define el comportamiento, pero omite un namespace o una decisión necesaria para que el archivo compile.

**When:** La skill genera la suite.

**Then:** La salida incluye `## 1. Assumptions` con el mínimo supuesto explícito y no inventa reglas adicionales.

## Caso 4 — Tres o más escenarios equivalentes

**Given:** Un validador público debe rechazar cuatro formatos inválidos con la misma excepción.

**When:** La skill genera las pruebas.

**Then:** Usa `#[DataProvider(...)]`, un provider `public static`, datasets nombrados y evita cuatro métodos duplicados.

## Caso 5 — Dependencias y comportamiento observable

**Given:** Un servicio consulta un repositorio y guarda una entidad solo cuando una regla pública se cumple.

**When:** La skill genera las pruebas.

**Then:** Usa un stub para controlar la consulta y un mock con expectativa para verificar el guardado; no inspecciona propiedades internas.

## Caso 6 — Excepción contractual

**Given:** El requisito especifica clase, mensaje y código de excepción.

**When:** La skill genera el caso inválido.

**Then:** Incluye las tres marcas AAA exactas, coloca las expectativas inmediatamente antes de la acción y verifica clase, mensaje y código.

## Caso 7 — Infraestructura prohibida

**Given:** El componente depende de SQL Server, una API HTTP, Redis y el reloj.

**When:** La skill diseña pruebas unitarias.

**Then:** Sustituye todas esas dependencias por dobles controlados, no usa red, filesystem, espera, aleatoriedad ni tiempo real.

## Caso 8 — Separación estricta de producción

**Given:** La especificación describe una clase aún inexistente.

**When:** La skill entrega el resultado.

**Then:** Genera exclusivamente pruebas compilables contra la interfaz declarada y no incluye implementación, pseudocódigo, `TODO` ni consejos posteriores.

## Caso 9 — Controlador AudFact

**Given:** Un controlador extiende `App\Controllers\Controller`, valida body JSON, usa una factory protegida y responde mediante `Core\Response`.

**When:** La skill genera la prueba unitaria.

**Then:** Crea una subclase testable para inyectar el doble, captura `HttpResponseException` como respuesta observable y afirma código y payload sin probar métodos protegidos.

## Caso 10 — Modelo SQL Server AudFact

**Given:** Un modelo extiende `App\Models\Model` y ejecuta una consulta pública mediante `read()`.

**When:** La skill genera la prueba unitaria.

**Then:** Inyecta `SqlServerConnectionExecutor` con connector/sleeper controlados, usa PDO y statement falsos y no abre SQL Server real.

## Caso 11 — Namespace y base de test AudFact

**Given:** Se solicita probar una clase nueva en `App\Services\Audit\Pipeline`.

**When:** La skill determina el archivo de prueba.

**Then:** Usa la ruta `tests/Services/Audit/Pipeline`, namespace `Tests\Services\Audit\Pipeline` y extiende directamente `PHPUnit\Framework\TestCase`; no copia el directorio legacy `Events` ni inventa una base propia.

## Caso 12 — Dependencia concreta sin interfaz

**Given:** Un servicio AudFact recibe una clase concreta extensible y el repositorio no declara una interfaz equivalente.

**When:** La skill diseña el doble.

**Then:** Usa stub/mock PHPUnit cuando sea viable o un fake local que sobrescribe la API pública; no inventa una interfaz ni un contenedor DI.
