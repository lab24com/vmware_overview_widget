# VMware: Vista general para Zabbix

Widget de dashboard para consolidar en una sola ventana las máquinas virtuales y los hipervisores descubiertos por la plantilla oficial **VMware** de Zabbix.

## Fuente de datos

El módulo no modifica la plantilla ni crea ítems. Lee los ítems generados por las plantillas oficiales **VMware Guest** y **VMware Hypervisor**, entre ellos:

- `vmware.vm.powerstate`
- `vmware.vm.cpu.usage.perf`
- `vmware.vm.cpu.num`
- `vmware.vm.memory.size`
- `vmware.vm.memory.size.usage.guest`
- `vmware.vm.storage.committed`
- `vmware.vm.hv.name`
- `vmware.vm.cluster.name`
- `vmware.vm.tools`
- `vmware.vm.guest.osuptime`
- `vmware.vm.property[...,guest.guestFullName]`
- `vmware.vm.vfs.fs.size`
- `vmware.hv.connectionstate`
- `vmware.hv.status`
- `vmware.hv.cpu.usage.perf`
- `vmware.hv.cpu.utilization`
- `vmware.hv.memory.used`
- `vmware.hv.hw.memory`
- `vmware.hv.hw.cpu.num`
- `vmware.hv.hw.cpu.threads`
- `vmware.hv.network.in`
- `vmware.hv.network.out`
- `vmware.hv.uptime`
- `vmware.hv.vm.num`
- `vmware.datastore.size`
- `vmware.hv.datastore.size`
- `vmware.datastore.read` / `vmware.datastore.write` (modos `latency`, `rps`, `wps`)
- `vmware.vm.cpu.readiness`
- `vmware.vm.memory.size.ballooned` / `vmware.vm.memory.size.swapped`
- `vmware.vm.consolidationneeded` (mapa de la plantilla: 0 = True)
- `vmware.vm.snapshot.count` / `vmware.vm.snapshot.latestdate`
- `vmware.vm.hv.maintenance` (sólo plantilla de Zabbix 8.0)

> **Sistema operativo:** la plantilla oficial no crea el ítem `vmware.vm.property[...,guest.guestFullName]`. Para que la columna SO se rellene, agrega ese ítem a la plantilla "VMware Guest" o completa el inventario del host.

Las unidades invitadas dependen de `vmware.vm.vfs.fs.discovery`. **VMware Tools debe estar instalado y funcionando en la VM** para que vCenter pueda entregar sus sistemas de archivos.

## Compatibilidad

- Objetivo: Zabbix 7.0 LTS, 7.2, 7.4 y 8.0 (verificado contra el código fuente de las ramas `release/7.0`, `release/7.4` y `master`/8.0: manifiesto, campos de formulario, `CControllerDashboardWidgetView`, API JavaScript de widgets y plantilla VMware).
- Plantilla base recomendada: VMware oficial de la misma versión del servidor.
- Conserva compatibilidad con el manifiesto v2 y utiliza las claves estables de las plantillas oficiales VMware de Zabbix 7.x/8.x.
- No requiere acceso directo del navegador a vCenter.
- Respeta los permisos del usuario conectado en Zabbix.

## Instalación

### Frontend instalado desde paquetes zip

```bash
unzip -o vmware_overview_widget.zip -C /usr/share/zabbix/modules/
find /usr/share/zabbix/modules/vmware_overview_widget -type d -exec chmod 755 {} \;
find /usr/share/zabbix/modules/vmware_overview_widget -type f -exec chmod 644 {} \;
restorecon -Rv /usr/share/zabbix/modules/vmware_overview_widget
```

Después:

1. Ir a **Administration → General → Modules**.
2. Pulsar **Scan directory**.
3. Habilitar **VMware: Vista general**.
4. Editar un dashboard y agregar el widget.
5. Seleccionar opcionalmente grupos de hosts y ajustar filas y la sección **Umbrales de uso**.




