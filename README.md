![alt tag](http://i.imgur.com/nu63B1J.png?1)

####A fast, lightweight PHP API framework that responds to API requests with JSON, ensuring that your backend is completely separated from your front-end

##### Please note: Rxn is currently under active development and is still considered *early* alpha

Rxn (pronounced 'reaction') is a framework designed to cut out the complexity and clutter of PHP-generated views -- offloading views to whatever frontend that suits your fancy.

The philosophy behind Rxn is simple: **strict backend / frontend decoupling**.

1. The **backend** should *only* be accessible via API
2. The **backend** should *only* render responses as JSON
3. The **frontend** should be responsible for interpreting JSON responses
4. The **frontend** should be responsible for generating user views
5. Through strict **backend / frontend decoupling**, amazing things can happen
  *  Both the **backend** and **frontend** *can be developed separately* using versioned API contracts as reference
  *  Both the **backend** and **frontend** *have less entangling complexity*, providing a simple and clean workflow
  *  Either the **backend** or **frontend** *can be swapped out entirely* with a completely different solution, giving you greater flexibility further down the road

##### Some of the features that Rxn currently offers in alpha (or aims to offer by beta):
- [X] Simple workflow with an existing database schema *(just create models and controllers -- that's it!)*
- [X] Database abstraction and security
   - [X] PDO
   - [X] Prepared statements
   - [X] Support for multiple database connections
- [X] Robust error handling *(throw an exception anywhere and RXN handles the rest)*
- [X] Fantastic debugging utilities *(inlcuding a powerful and simple alternative to var_dump and print_r)*
- [X] Support for versioned controllers and versioned actions *(saving you API maintenance hassles down the road)*
- [X] URI Routing
   - [X] using Apache2
   - [X] using NGINX *(currently experimental)*
- [X] Dependency Injection (DI) service container
   - [X] Controller method injection
   - [X] DI autowiring *(constructor parameters automatically injected using type-hinting)*
- [X] Object Relational Mapping (ORM)
   - [X] Rxn-ORM
      - [X] CRUD operations on any database record or relation table
      - [X] ORM autowiring *(relationships derived from database structure and foreign keys)*
      - [ ] Soft deletes
   - [ ] Support for third-party ORMs
- [X] Speed and Performance
   - [X] Autoloading of only necessary classes *(extremely small footprint)*
   - [X] Caching mechanisms
       - [X] Native query caching *(with expiration)*
       - [X] Object file caching *(blazing fast instantiation)*
   - [ ] Compiled extensions
- [ ] Authentication  
   - [ ] Support for third-party libraries
     - [ ] OAUTH2
     - [ ] OpenId
     - [ ] SAML 
- [ ] Angular admin frontend
  - [ ] API documentation
  - [ ] Scheduler interface
  - [ ] Integration test interface
  - [ ] Database schema migrations
- [ ] Sample angular frontend templates
- [ ] Automated validation of API requests using existing (or generated) API contracts
- [ ] Event logging
- [ ] Mailer
- [ ] Scheduler
- [ ] Optional, modular plug-ins for loose coupling and greater flexibility
