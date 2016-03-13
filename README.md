# Reaction (RXN)

####A fast, lightweight PHP API micro-framework that responds to API requests with JSON, ensuring that your backend is completely separated from your front-end

##### Please note: RXN is currently under active development and is still considered alpha

Reaction, or RXN for short, is a framework designed to cut out the complexity and clutter of PHP-generated views -- offloading views to whatever frontend that suits your fancy.

The philosophy behind RXN is simple: **strict backend / frontend decoupling**.

1. The **backend** should *only* be accessible via API
2. The **backend** should *only* render JSON responses
3. *Only* the **frontend** should be responsible for interpreting JSON responses
4. *Only* the **frontend** should be responsible for generating user views
5. Through strict **backend / frontend decoupling**, amazing things can happen
  *  Both the **backend** and **frontend** *can be developed concurrently yet separately*, using versioned API contracts as reference
  *  Both the **backend** and **frontend** *have less entangling complexity*, giving you a simple and clean workflow
  *  Either the **backend** or **frontend** *can be swapped out entirely* with a completely different solution, giving you greater flexibility if needed

Some of the features that RXN currently offers (or aims to offer):

- [X] Simple workflow with an existing database schema *(just create models and controllers -- that's it!)*
- [X] Database abstraction and security *(using PDO and prepared statements)*
- [X] Robust error handling *(just throw an exception anywhere and RXN handles the rest)*
- [X] Fantastic debugging utilities *(you have to see them to believe them)*
- [X] Support for versioned controllers and versioned actions *(saving you API maintenance hassles down the road)*
- [X] Database caching
- [X] Routing using Apache2
- [ ] Routing using NGINX
- [ ] Optional, modular plug-ins for more advanced funtionality
- [ ] Support for multiple authentication standards, including OAUTH2
- [ ] Generated API documentation *(via an optional Angular admin frontend)*
- [ ] Database schema migrations *(via an optional Angular admin frontend)*
- [ ] An advanced ORM where model relationships are derived from database structure and foreign keys
- [ ] CRUD operations on any database record or relation table using the RXN ORM
- [ ] Automated validation of API requests using existing (or generated) API contracts
- [ ] Event logging on RXN Record CRUD operations
- [ ] File caching
- [ ] Speed improvements *(with an eye on compiled extensions)*
