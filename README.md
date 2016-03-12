# Reaction (RXN)

####A PHP micro-framework that responds to API requests with JSON, ensuring that your backend is completely separated from your front-end

##### Please note: RXN is currently under active development and is still considered alpha

Reaction, or RXN for short, is a framework designed to cut out the complexity and clutter of PHP-generated views -- offloading views to whatever frontend that suits your fancy.

The philosophy behind RXN is simple: 
1. The backend should only be accessible using APIs
2. The backend should only render responses using JSON
3. The frontend should exclusively generate views
4. Through strict backend / frontend decoupling, amazing things can happen

Some of the features that RXN currently offers:
* Simple workflow with an existing database schema (just create models and controllers -- that's it!)
* Database abstraction and security (using PDO and prepared statements)
* Robust error handling (just throw Exceptions and RXN handles the rest)
* Fantastic debugging utilities (you have to see them to believe them)
* Support for versioned controllers and versioned actions (saving you API maintenance hassle down the road)
* Database caching

Some of the features that are currently on the horizon:
* Optional, modular plug-ins for more advanced funtionality
* Support for multiple authentication standards
* Generated API documentation (via an optional Angular admin frontend)
* Database schema migrations (via an optional Angular admin frontend)
* An advanced ORM where model relationships are derived from database structure and foreign keys
* CRUD operations on any database record or relation table using the RXN ORM
* Automated validation of API requests using existing (or generated) API contracts
* Event logging on RXN Record CRUD operations
* File caching
* Speed improvements (with an eye on compiled extensions)
