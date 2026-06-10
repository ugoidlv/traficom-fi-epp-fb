# FOSSBilling .FI EPP Registrar Plugin by UGO.ID.LV

A native, lightweight EPP registrar module built for FOSSBilling to provision and manage Finnish `.fi` domains directly through the Traficom registry.

## Features
- Maintained & provided by **UGO.ID.LV**.
- Native lightweight PHP EPP integration (No heavy external dependencies or vendor folders).
- Safe TCP/TLS connection protocol engine.
- Supports Traficom `.fi` custom identity attribute requirements for registrants.
- Automation operations covered: Domain Registration, Domain Renewal, Domain Transfer (Change Key), and Domain Deletion.

## Installation
1. Download the file `TraficomFiEppFb.php`.
2. Move it into your FOSSBilling installation directory: `library/Registrar/Adapter/TraficomFiEppFb.php`.
3. Log in to your FOSSBilling Admin Dashboard -> System -> Domain Registration -> Registrars.
4. Activate **UGO.ID.LV :FI EPP Registrar** and add your Traficom live/test credentials.

## License
MIT License
