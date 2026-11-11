'use strict';

// Standalone CI canary for the Laravel runtime controller-signature audit.
// This deliberately does not replace executing the PHP Feature test.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const php = fs.readFileSync(path.join(
  __dirname, '../services/business-api/tests/Feature/TypedIntegerControllerRouteIntegrityTest.php'
), 'utf8');

test('registered route reflection catches int parameters with no numeric constraint at all', () => {
  assert.match(php, /foreach \(Route::getRoutes\(\) as \$route\)/);
  assert.match(php, /new ReflectionMethod\(\$class, \$method\)/);
  assert.match(php, /new ReflectionMethod/);
  assert.match(php, /\$argument->getType\(\)/);
  assert.match(php, /\$type->getName\(\) !== 'int'/);
  assert.match(php, /\$route->parameterNames\(\)/);
  assert.match(php, /\$route->wheres\[\$parameter\] \?\? null/);
  assert.match(php, /!== '\[0-9\]\{1,18\}'/);
  assert.match(php, /assertSame\(\[\], \$unbounded/);
});

test('controller route audit rejects a vacuous scan and preserves model/string exclusions', () => {
  assert.match(php, /assertGreaterThan\(50, \$checked/);
  assert.match(php, /! \$type instanceof ReflectionNamedType/);
  assert.match(php, /Eloquent model bindings and validated string parameters/);
  assert.match(php, /in_array\(\$parameter, \$uriParameters, true\)/);
});
