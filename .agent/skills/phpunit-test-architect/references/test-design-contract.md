# Contrato de diseño de pruebas PHPUnit

## Contenido

1. [Responsabilidad y fuente de verdad](#responsabilidad-y-fuente-de-verdad)
2. [Stack objetivo](#stack-objetivo)
3. [Principios de diseño](#principios-de-diseño)
4. [Cobertura requerida](#cobertura-requerida)
5. [Colecciones](#colecciones)
6. [Identidad y seguridad de tipos](#identidad-y-seguridad-de-tipos)
7. [Data providers](#data-providers)
8. [Estructura AAA](#estructura-aaa)
9. [Nombres y archivos](#nombres-y-archivos)
10. [Dobles de prueba](#dobles-de-prueba)
11. [Excepciones](#excepciones)
12. [Duplicación y preparación](#duplicación-y-preparación)
13. [Determinismo e infraestructura](#determinismo-e-infraestructura)
14. [Supuestos](#supuestos)
15. [Prohibiciones](#prohibiciones)
16. [Lista de calidad](#lista-de-calidad)

## Responsabilidad y fuente de verdad

- Analizar el dominio, interfaz de clase, firma de función, especificación técnica o requisito funcional entregado.
- Producir una suite unitaria PHPUnit completa y lista para producción.
- Asumir que el código de producción no existe.
- Diseñar las pruebas para que otro agente pueda implementar toda la funcionalidad usando únicamente la suite como contrato ejecutable.
- Describir comportamiento de negocio y resultados observables; no fijar detalles internos de implementación.
- Priorizar claridad y mantenibilidad sobre concisión o ingenio.

## Stack objetivo

- PHP 8.2 o superior.
- PHPUnit 10 o superior.
- PSR-12.
- `declare(strict_types=1);` en todos los archivos.
- Autoloading de Composer.
- Sintaxis moderna de PHPUnit exclusivamente.
- Ninguna API de PHPUnit deprecada.

## Principios de diseño

Cada prueba debe:

- ser independiente de las demás;
- producir el mismo resultado ante el mismo contrato;
- poder ejecutarse en cualquier orden;
- no depender de una prueba previa;
- evitar estado externo o compartido;
- poder ejecutarse en paralelo;
- expresar claramente la intención de negocio;
- validar únicamente comportamiento observable mediante la API pública.

No probar:

- métodos privados;
- métodos protegidos;
- propiedades internas;
- pasos de un algoritmo;
- estado interno que no forme parte del contrato observable.

## Cobertura requerida

Para cada método público, generar todos los escenarios aplicables y omitir únicamente los que la naturaleza del contrato haga irrelevantes.

### Camino exitoso

Verificar el resultado esperado con entradas válidas y las interacciones públicas exigidas.

### Condiciones límite

Evaluar, cuando corresponda:

- valor mínimo;
- valor máximo;
- cadena vacía;
- arreglo vacío;
- cero;
- uno;
- valores negativos;
- valores grandes.

No inventar máximos ni mínimos que la especificación no defina. Si un límite es necesario para completar el contrato pero no está documentado, declararlo como supuesto.

### Entrada inválida

- Verificar la conducta documentada para tipos, formatos o valores inválidos.
- Esperar excepciones cuando el contrato indique que el uso inválido debe rechazarse.
- No convertir una ausencia de especificación en una validación inventada.

### Excepciones

Comprobar todos los datos documentados:

- clase de excepción;
- mensaje, cuando esté especificado;
- código, cuando esté especificado.

No ignorar ni suavizar el comportamiento excepcional.

### Nulabilidad

Cuando existan parámetros o retornos anulables, verificar explícitamente:

- entrada `null`;
- salida `null`.

No tratar `null`, cadena vacía, cero o arreglo vacío como equivalentes salvo que el contrato así lo declare.

## Colecciones

Cuando el comportamiento involucre colecciones, cubrir según corresponda:

- colección vacía;
- un elemento;
- múltiples elementos;
- elementos duplicados;
- orden;
- filtrado;
- transformación;
- búsqueda.

Verificar el tipo, contenido, cardinalidad y orden solo cuando formen parte del contrato público.

## Identidad y seguridad de tipos

Preferir siempre aserciones estrictas. Usar:

```php
$this->assertSame($expected, $actual);
```

en lugar de:

```php
$this->assertEquals($expected, $actual);
```

Usar `assertEquals()` únicamente cuando el contrato requiera igualdad de valor sin identidad estricta, y hacer evidente esa intención en el nombre de la prueba.

Seleccionar la aserción semántica más precisa, entre ellas:

- `assertTrue()`;
- `assertFalse()`;
- `assertNull()`;
- `assertNotNull()`;
- `assertCount()`;
- `assertContains()`;
- `assertEmpty()`;
- `assertInstanceOf()`;
- `assertStringContainsString()`.

Evitar aserciones genéricas si una aserción específica comunica mejor el contrato.

## Data providers

Cuando existan tres o más escenarios con la misma preparación, acción y forma de aserción, usar un data provider en vez de duplicar métodos.

Usar atributos modernos:

```php
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('provideValidCases')]
public function testReturnsExpectedResultForValidInput(
    string $input,
    string $expected,
): void {
    // Arrange:
    $service = $this->createService();

    // Act:
    $actual = $service->execute($input);

    // Assert:
    $this->assertSame($expected, $actual);
}

public static function provideValidCases(): iterable
{
    yield 'minimum value' => ['a', 'A'];
    yield 'regular value' => ['example', 'EXAMPLE'];
    yield 'large value' => [str_repeat('a', 1000), str_repeat('A', 1000)];
}
```

- El método proveedor debe ser `public static` para PHPUnit 10+.
- Nombrar cada dataset para comunicar su intención.
- Mantener los datos deterministas.
- No ocultar preparaciones sustancialmente distintas dentro de un único provider.

## Estructura AAA

Todo método de prueba debe incluir estas marcas exactamente:

```php
// Arrange:
```

```php
// Act:
```

```php
// Assert:
```

Para comportamiento normal, respetar el orden `Arrange`, `Act`, `Assert`.

Para excepciones, la expectativa de PHPUnit debe quedar inmediatamente antes de la acción que dispara la excepción. Usar el orden indicado por el contrato:

```php
// Arrange:
$service = new UserService();

// Assert:
$this->expectException(InvalidArgumentException::class);

// Act:
$service->register('invalid');
```

No configurar expectativas de excepción al principio del método si existe preparación posterior.

## Nombres y archivos

Usar nombres descriptivos en camelCase. El nombre debe expresar qué se prueba, bajo qué condición y cuál es la conducta esperada.

Ejemplos:

- `testUserCanRegisterWithValidEmail()`;
- `testUserCannotRegisterWithDuplicateEmail()`;
- `testCalculateTotalReturnsZeroWhenCartIsEmpty()`;
- `testThrowsExceptionWhenProductDoesNotExist()`.

Cada archivo debe comenzar exactamente con:

```php
<?php

declare(strict_types=1);
```

Cada clase de prueba debe:

- extender `PHPUnit\Framework\TestCase`;
- usar un namespace coherente con el autoloading;
- importar todas las clases necesarias;
- ser `final` salvo que el contrato del proyecto requiera herencia;
- tener una sola responsabilidad;
- contener métodos de prueba con retorno `void`.

## Dobles de prueba

Nunca acceder en una prueba unitaria a:

- base de datos real;
- sistema de archivos real;
- red;
- API HTTP;
- colas;
- cachés;
- servicios externos.

### Stubs

Usar stubs cuando solo se necesitan valores de retorno:

```php
$repository = $this->createStub(UserRepository::class);
$repository->method('findById')->willReturn($user);
```

### Mocks

Usar mocks únicamente para verificar una interacción observable requerida:

```php
$repository = $this->createMock(UserRepository::class);
$repository
    ->expects($this->once())
    ->method('save')
    ->with($user);
```

Nunca crear un mock sin una expectativa de interacción. No verificar llamadas que no formen parte del contrato de negocio.

### Clases anónimas

Se permiten clases anónimas cuando simplifican una sustitución pequeña y mantienen la intención más clara que un mock complejo.

## Excepciones

- Colocar `expectException()` inmediatamente antes de la acción.
- Añadir `expectExceptionMessage()` solo si el mensaje está especificado.
- Añadir `expectExceptionCode()` solo si el código está especificado.
- No envolver la acción en `try/catch` para simular una expectativa que PHPUnit expresa de forma nativa.
- No afirmar detalles incidentales del stack trace o de la implementación interna.

## Duplicación y preparación

- Extraer preparación repetida a métodos privados cuando aumente la claridad.
- Usar `setUp()` solo si varias pruebas comparten una precondición esencial y explícita.
- No ocultar datos importantes del escenario en una jerarquía de helpers.
- No sobreutilizar `setUp()`.
- Usar factories privadas deterministas para objetos de prueba complejos.

## Determinismo e infraestructura

- No usar `sleep()`.
- No usar valores aleatorios.
- No consultar el reloj real ni crear timestamps actuales sin un reloj controlable.
- No depender de variables de entorno mutables.
- No depender del orden de ejecución.
- No compartir instancias mutables entre pruebas.
- Sustituir reloj, identificadores, persistencia, red, colas y cachés por dependencias controladas cuando sean parte del contrato.

## Supuestos

Cuando la especificación esté incompleta:

- documentar cada supuesto necesario de forma explícita y breve;
- no inventar reglas ocultas;
- no inferir efectos secundarios no documentados;
- probar únicamente conductas razonablemente derivables del insumo;
- conservar nombres, namespaces y firmas explícitas provistas por el usuario;
- hacer que el código compile suponiendo que existen las interfaces de producción declaradas.

Si no hace falta ningún supuesto, omitir por completo la sección de supuestos.

## Prohibiciones

Nunca:

- implementar código de producción;
- explicar cómo implementar el código de producción;
- generar pseudocódigo;
- dejar `TODO` o pruebas incompletas;
- usar aserciones de relleno;
- probar detalles privados o protegidos;
- depender de infraestructura o estado externo;
- usar esperas, aleatoriedad o tiempo no controlado;
- depender del orden de ejecución;
- generar una suite que requiera otra prueba para preparar su estado.

## Lista de calidad

Antes de emitir la respuesta, comprobar internamente:

- [ ] Cada prueba es independiente y determinista.
- [ ] Cada comportamiento público aplicable está cubierto.
- [ ] Los caminos exitosos están cubiertos.
- [ ] Los límites documentados o declarados como supuesto están cubiertos.
- [ ] Las entradas inválidas están cubiertas cuando aplica.
- [ ] Las excepciones verifican tipo y, solo si están especificados, mensaje y código.
- [ ] La nulabilidad y las colecciones están cubiertas cuando aplica.
- [ ] Las dependencias externas usan stubs o mocks.
- [ ] Cada mock verifica una interacción contractual.
- [ ] No se prueban detalles de implementación.
- [ ] Tres o más casos equivalentes usan `#[DataProvider(...)]`.
- [ ] Cada método de prueba contiene las tres marcas AAA exactas.
- [ ] La suite usa PHPUnit 10+, PSR-12 y tipos estrictos.
- [ ] No hay lógica duplicada que pueda extraerse sin perder legibilidad.
- [ ] No existe código de producción, pseudocódigo, `TODO` ni aserciones vacías.
- [ ] Otro agente puede implementar la funcionalidad usando únicamente esta suite.
