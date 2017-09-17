![alt tag](http://i.imgur.com/nu63B1J.png?1)

#### A fast, simple, and powerful API framework for PHP. Responds to API requests with JSON, ensuring that your backend is completely separated from your frontend

##### Please note: Rxn is currently under active development and is still considered *early* alpha

Rxn (the abbreviation for 'reaction') is a framework designed to cut out the complexity and clutter of PHP-generated views -- offloading views to whatever frontend that suits your fancy.

The philosophy behind Rxn is simple: **strict backend / frontend decoupling**.

1. The **backend** should *only* be accessible via API
2. The **backend** should *only* render responses as JSON
3. The **frontend** should be responsible for interpreting JSON responses
4. The **frontend** should be responsible for generating user views
5. Through strict **backend / frontend decoupling**, amazing things can happen
  *  Both the **backend** and **frontend** *can be developed separately* using versioned API contracts as reference
  *  Both the **backend** and **frontend** *have less entangling complexity*, providing a simple and clean workflow
  *  Either the **backend** or **frontend** *can be swapped out entirely* with a completely different solution, giving you greater flexibility further down the road
  *  Parallel **frontend** development *becomes grossly simplified* (e.g., leveraging an internal app, external app, phone app, etc using the same **backend**).

## Features In Alpha

Including planned features for beta (unchecked):
- [ ] 80%+ unit test code coverage
- [X] Gentle learning curve *(you don't have to be a guru to get up and running)*
   - [ ] Installation through Composer
- [X] Simple workflow with an existing database schema
   - [ ] Code generation
      - [ ] Command Line Interface (CLI) utility to create controllers and models
- [X] Database abstraction
   - [X] PDO for multiple database support
   - [X] Support for multiple database connections
- [X] Security
   - [X] Prepared statements *(aids against SQL injection attacks)*
   - [ ] I/O sanitization  *(aids against XSS attacks)*
   - [ ] Session synchronizer tokens *(aids against CSRF attacks)* 
   - [ ] SSL/TLS support *(aids against man-in-the-middle attacks)*
- [X] Robust error handling *(throw an exception anywhere and Rxn handles the rest)*
- [X] Debugging utilities
- [X] Versioning *(saving you API maintenance hassles down the road)*
   - [X] Versioned Controllers
   - [X] Versioned Actions
- [X] Scaffolding *(fast application prototyping)*
   - [X] Version-less CRUD endpoints that reflect current backend records
- [X] URI Routing
   - [X] using Apache2
   - [X] using NGINX
- [X] Dependency Injection (DI) service container
   - [X] Controller method injection
   - [X] DI autowiring *(constructor parameters automatically injected using type-hinting)*
- [X] Object Relational Mapping (ORM)
   - [X] Rxn-ORM
      - [X] CRUD operations on any database record or relation table
      - [X] ORM autowiring *(relationships automatically derived from database structure and foreign keys)*
      - [ ] Soft deletes
   - [ ] Support for third-party ORMs
- [X] Speed and Performance
   - [X] PSR-4 Autoloading *(small footprint)*
   - [X] Caching mechanisms
       - [X] Native query caching *(with expiration)*
       - [X] Object file caching *(blazing fast instantiation)*
- [ ] Authentication  
   - [ ] Support for third-party libraries
     - [ ] OAUTH2
     - [ ] OpenId
     - [ ] SAML 
- [ ] Automated validation of API requests using existing (or generated) API contracts
- [ ] Event logging
- [ ] Mailer
- [ ] Scheduler
- [ ] Optional, modular plug-ins for loose coupling and greater flexibility

## License

Rxn is released under the permissive and free [MIT](http://flightphp.com/license) license.

## Hierarchical Namespacing and Autoloading

Rxn uses a namespacing structure that explicitly matches the directory structure of the class files. While also convenient, it is mainly used to implement some pretty cool autoloading features.

Say, for example, that you created a class named `\Organization\Product\Model\MyAwesomeModel`. Just put the file in a directory structure that follows the namespace convention (e.g., `{root}/organization/product/model/MyAwesomeModel.php`). When you need to call the class, just invoke the class by calling it directly. There's no need to put a `require` anywhere.

**BEFORE (not using autoloading):**
```php
<?php

require_once('/organization/product/model/MyAwesomeModel.php');

use \Organization\Product\Model\MyAwesomeModel;

$object = new MyAwesomeModel()
// object gets created!
```

**AFTER (using autoloading):**
```php
<?php

use \Organization\Product\Model\MyAwesomeModel;

$object = new MyAwesomeModel()
// object gets created!
```

The same pattern exists for Rxn's native classes. For example, the response class (`\Rxn\Api\Controller\Response`) is found in the `{root}/rxn/api/controller` directory. Autoloading is one of the many ways in which Rxn reduces overhead.

The following file extensions are supported by the autoloading feature (you may also define custom extensions in `\Rxn\Config`):

* .php
* .class.php
* .interface.php
* .model.php
* .controller.php


## Error Handling
Rxn lives, breathes, and eats exceptions. Consider the following code snippet:
```php
try {
    $result = $databse->query($sql,$bindings);
} catch (\PDOException $e) {
    throw new \Exception("Something went terribly wrong!",422);
}
```
If you throw an `\Exception` anywhere in the application, Rxn will self-terminate, roll back any in-process database transactions, and then gracefully respond using JSON:

```javascript
{
    "_rxn": {
        "success": false,
        "code": 422,
        "result": "Unprocessable Entity",
        "message": "Something went terribly wrong!",
        //...
    }
}
```

## Routing Request Parameters

An example API endpoint for your backend with Rxn might look like this:

```
https://yourapp.tld/v2.1/order/doSomething
```

Where:

1. `v2.1` is the endpoint `version`
2. `order` is the `controller`
3. `doSomething` is the controller's `action` (a public method)

Now if you wanted to add a GET key-value pair to the request where `id`=`1234`, in PHP you would normally do this:

**BEFORE:**
```
https://yourapp.tld/v2.1/order/someAction?id=1234
````
In Rxn, you can simplify this by putting the key and value in the URL using the forward slash (`/`) as the separator, like so:

**AFTER:**
```
https://yourapp.tld/v2.1/order/someAction/id/1234
```
An *odd* number of parameters after the `version`, `controller`, and `action` would result in an error.

## Versioned Controllers & Actions
By versioning your endpoint URLs (e.g., `v1.1`, `v2.4`, etc), you can rest easy knowing that you're not going to accidentally break your frontend whenever you alter backend endpoint behavior. Additionally, versioning also helps keep your documentation in order; frontend developers can just build to the documentation and everything will *just work*.

So for an endpoint with version `v2.1`, the first number (`2`) is the *controller version*, and the second number (`1`) is the *action version*. The example below is how we would declare controller version `2` with action version `1`:

```php
namespace Organization\Product\Controller\v2;

class Order extends \Rxn\Api\Controller
{
    public function doSomething_v1() {
        //...
    }
}
```
 This allows for maintainable, true-to-reality documentation that both frontend and backend developers can get behind.

## Scaffolding

Want to experiment and explore with your newfangled backend architecture? No problem, as long as you have a database schema, you have a suite of scaffolding APIs to toy with! Scaffolding endpoints are accessed using URIs that are similar to the following (note the `api` instead of the version number):
```
https://yourapp.tld/api/order/create
https://yourapp.tld/api/order/read/id/{id}
https://yourapp.tld/api/order/update/id/{id}
https://yourapp.tld/api/order/delete/id/{id}
https://yourapp.tld/api/order/search
```
Scaffolding APIs are version-less APIs, and are designed to allow frontend developers full access to the backend in the form of Create, Read, Update, and Delete (CRUD) operations and searches. Their main benefit is that you don't have to spend a ton of time manually crafting CRUD endpoints during the early phases of application development. (As it is these early phases of development when requirements are changing, and things are constantly in flux.)

**Warning:** Because Scaffolding APIs are version-less, they inheret all the problems associated with version-less APIs. As soon as the backend is altered, these APIs are altered as well; this can potentially break an application in unexpected or hidden ways. For this reason, it is wise to transition versionless APIs to versioned APIs as the development process nears completion.

## Dependency Injection (DI) Service Container
While most people practice some form of dependency injection without even thinking about it, the fact is, manually instantiating and injecting classes with a lot of dependencies can be a pretty big hassle. The following examples should help demonstrate the benefit of automatic dependency injection via the service container.

**BEFORE (manual DI):**
```php
// instantiate the dependencies
$config    = new Config();
$database  = new Database($config);
$registry  = new Registry($config,$database);
$filecache = new Filecache($config);
$map       = new Map($registry,$database,$filecache);

// call the action method
$this->doSomething_v1($registry,$database,$map);

public function doSomething_v1(Registry $registry, Database $database, Map $map) {
    $customer = new Customer($registry,$database,$map);
    //...
}
```

**AFTER (using the DI service container):**
```php
// call the action method
$this->doSomething_v1($app->service);

public function doSomething_v1(Service $service) {
    $customer = $service->get(Customer::class);
    //...
}
```
Hopefully you can see the benefits. With Rxn, there's no need to instantiate the prerequisites every time! Use the service container to make your life easier.

## Controller Method Injection
Just typehint the class you need as a parameter, and *poof*, the DI service container will guess all of the dependencies for you and automatically load and inject them. No messy requires. *You don't have to inject the dependencies manually!*

**BEFORE (manual instantiation):**
```php
// require the dependencies
require_once('/path/to/Config.php');
require_once('/path/to/Collector.php');
require_once('/path/to/Request.php');

public function doSomething_v1() {
    // instantiate the dependencies
    $config = new Config();
    $collector = new Collector($config);
    $request = new Request($collector,$config);
    
    // grab the id from the request
    $id = $request->collectFromGet('id');
    //...
}
```
**AFTER (automatic instantiation and injection):**
```php
public function doSomething_v1(Request $request) {
    // grab the id from the request
    $id = $request->collectFromGet('id');
    //...
}
```
See the difference?
