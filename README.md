# Reaction (RXN)

####A fast, lightweight PHP API framework that responds to API requests with JSON, ensuring that your backend is completely separated from your front-end

##### Please note: RXN is currently under active development and is still considered *early* alpha

Reaction, or RXN for short, is a framework designed to cut out the complexity and clutter of PHP-generated views -- offloading views to whatever frontend that suits your fancy.

The philosophy behind RXN is simple: **strict backend / frontend decoupling**.

1. The **backend** should *only* be accessible via API
2. The **backend** should *only* render responses as JSON
3. The **frontend** should be responsible for interpreting JSON responses
4. The **frontend** should be responsible for generating user views
5. Through strict **backend / frontend decoupling**, amazing things can happen
  *  Both the **backend** and **frontend** *can be developed separately* using versioned API contracts as reference
  *  Both the **backend** and **frontend** *have less entangling complexity*, providing a simple and clean workflow
  *  Either the **backend** or **frontend** *can be swapped out entirely* with a completely different solution, giving you greater flexibility if needed

##### Some of the features that RXN currently offers in alpha (or aims to offer by beta):
- [X] Simple workflow with an existing database schema *(just create models and controllers -- that's it!)*
- [X] Database abstraction and security
   - [X] PDO
   - [X] Prepared statements
   - [X] Support for multiple database connections
- [X] Robust error handling *(throw an exception anywhere and RXN handles the rest)*
- [X] Fantastic debugging utilities *(you have to see them to believe them)*
- [X] Support for versioned controllers and versioned actions *(saving you API maintenance hassles down the road)*
- [X] URI Routing
   - [X] using Apache2
   - [ ] using NGINX
- [ ] Optional, modular plug-ins for more advanced funtionality
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
- [X] Object Relational Mapping (ORM)
   - [X] RXN-ORM
      - [ ] CRUD operations on any database record or relation table
      - [X] ORM autowiring *(relationships derived from database structure and foreign keys)*
   - [ ] Support for third-party ORMs 
- [ ] Automated validation of API requests using existing (or generated) API contracts
- [ ] Event logging
- [ ] Mailer
- [ ] Scheduler
- [X] Dependency injection (DI) container
   - [X] DI autowiring *(constructor parameters automatically injected using type-hinting)*
- [X] Speed improvements
   - [X] Caching mechanisms
       - [X] Database caching
       - [ ] File caching
   - [ ] Compiled extensions
