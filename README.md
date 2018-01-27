# MODX REST API Specification

This repository contains an OpenAPI 3.0 specification for a MODX REST API. 

For the reasoning and goals behind this specification, please see MAB Recommendation [MAB-06: A RESTful API & Steps to move away from ExtJS](https://github.com/modxcms/mab-recommendations/blob/master/06-restful-api-move-from-extjs.md). In short, the goal is to define a contract for a RESTful API for MODX.

## Status

The [current version of the API Spec is 0.2.0 and can be previewed on SwaggerHub](https://app.swaggerhub.com/apis/modmore/modx-api/0.2.0). 

This version of the API spec is automatically generated (by `php utils/build-models.php`) from the MODX core objects, with various transformations being done to provide a more logical structure. This means no manual changes to `spec/openapi.json` should be made. 

## Next Steps

Work is on-going, and contributions are welcome. If you'd like to help, please read up on MAB-06, and see the [issues](https://github.com/Mark-H/MODX-API/issues) for various open questions or tasks.

The plan for the next steps is as follows:

1. Continue working on transformations (in `utils/Builder.php`) that can generate a solid API structure from the core model. Fix any incorrect endpoints (e.g. endpoints that use `{id}` paths, while the underlying objects have a different primary key), make sense of endpoints by renaming them, adding the right path placeholders, etc.
2. Add different responses (404, 301, etc)
3. Add endpoints and instructions for authentication. The specification will likely require cookie authentication (piggy-backing on the current MODX authentication/session handling) and HTTP Basic Auth, but implementations could support additional authentication mechanisms, like OAuth2. 
4. Consider issues #3, #4 and #6 as improvements to add with the Builder.
5. Consider and implement additional feedback from the community that has been provided until this point
6. Break up the `spec/openapi.json` spec file into multiple files using `$ref`s for paths and models. At this point, the Builder utility will be deprecated and manual improvements to the spec are accepted.
7. Formally release the specification as v0.9, indicating breaking changes are still possible.
8. Build a Slim-based proof of concept implementation that can be released as a MODX extra.
9. Based on the experience of building the proof of concept, make any final changes to the spec, and formally release version 1.0.

## Future spec changes

After version 1.0, the specification will follow [semver](https://semver.org/) as version endpoint `v1`. Practically, this means that new features may be added and bugs/oversights in the specification can be fixed, without the endpoint changing. 

Descriptions/documentation as part of the endpoints that do not affect the endpoint interaction can also happen without the endpoint number incrementing. This includes marking endpoints as deprecated to be removed in the next major version.

When endpoints are removed, or changed in a way that requires clients to adjust the way they interact with an endpoint, that is a breaking change that increments the major version number and endpoint number.

## Questions, feedback, ideas?

[Please open an issue](https://github.com/Mark-H/MODX-API/issues) and/or join #modx-restful-api in the [MODX Community Slack](https://modx.org).
