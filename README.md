# REST API Laravel 8
API REST desarrollada en Laravel 8 que sirve como backend para suministrar de información al sistema, así como modificar información del mismo en la aplicación de capacitacion y monitoreo de movimientos de choferes CyM.


Para ejecutar la API de manera local es necesario tener instalado los siguientes programas:
- XAMPP: especificante con la versión 7.4 de PHP para evitar errores de compatibilidad. Link de descarga: https://versaweb.dl.sourceforge.net/project/xampp/XAMPP%20Windows/7.4.3/xampp-windows-x64-7.4.3-0-VC15-installer.exe
- Composer: luego de instalar XAMPP es muy importante instalar Composer para completar los paquetes o referencias que hagan falta al momento de clonar el proyecto. Link de descarga: https://getcomposer.org/download/
- Git: es necesario para clonar el proyecto y descargarlo de forma local en el equipo. Link de descarga: https://git-scm.com/downloads
- Un editor de código, por ejemplo, Visual Studio Code: https://code.visualstudio.com/ (es recomendable instalar extensiones para PHP en VS Code para tener una mejor experencia al desarrollar).

Procedimiento:
1. Una vez instalados los programas, es necesario acceder a la carpeta htdocs que XAMPP instala en la raiz del disco local y abrir la terminal, o bien abrir primero la terminal y dirigirese a la ruta de htdocs de XAMPP. Ejemplo: C:\xampp\htdocs
2. Una vez en la terminar, ingresar el comando: git clone https://github.com/lokiiiv/cym_choferes_laravel_api.git, se comenzará a descargar el proyecto en la carpeta de htdocs desde github.
3. Acceder desde la terminal a la carpeta del proyecto descarga desde github y ejecutar el comando: composer install
4. Es necesario generar el archivo .env nuevamente, por lo que es necesario hacer una copia del archivo .env.example y renombrarlo a .env. En este punto, se puede cambiar los parametros para la base de datos, indicando el nombre del host, nombre de base de datos, usuario o contraseña. La base de datos ya debe estar previamente importada desde PhpMyAdmin (para importar la base de datos no es necesario crear una nueva base de datos desde phpmyadmin, solo hay que ingresar a phpmyadmin y seleccionar la opcion de importar y escoger el archivo .sql y listo). Para modificar los archivos puede apoyarse de su editor de código favorito.
5. Para que funcione correctamente la aplicación laravel, es necesario ingresar el comando: php artisan key:generate. Este paso es muy importante.
6. Ahora es necesario ejecutar el comando: php artisan jwt:secret, esto con la finalidad de que las funcionalidades de la libreria de JWT Token funcionen correctamente.

Aspectos importantes:
Para que la aplicación funciones correctamente y otros clientes puedan conectarse a ella, es necesario saber la dirección IP del dispositivo donde esta corriendo la aplicación Laravel, puede obtenerse con el comando ipconfig en caso de Windows o ifconfig en caso de Linux, teniendo esta información, es necesario incluir esta dirección en el archivo .env con el puerto 8000, especificamente en la variable de "APP_URL", quedando al final por ejemplo: APP_URL=http://192.168.0.8:8000. Esto es necesario cambiarlo en caso de la direccion IP del servidor cambie debido a que se desconecto o se cambio de red.

Ejecutar la aplicación:
- Una vez terminados los pasos anteriores, se puede ejecutar la aplicación con el comando: php artisan serve --host 192.168.0.9 --port 8000 (cambiar la dirección IP por la dirección del servidor).
- Es importante ejecutar el siguiente comando posteriormente en otra terminal estando en la carpeta del proyecto:  php artisan schedule:work. Este comando permite correr algunas tareas programadas que se incluyen para el correcto funcionamiento de la aplicacion.


Para ejecutar los comandos sin abrir la terminal se puede hacer uso de Visual Studio Code que permite integrar la terminal mientras se trabaja en el proyecto.

Ahora puede realizar peticiones a la API usando Postman por ejemplo para obtener respuestas, usando la url indicanda, por ejemplo: http://192.168.0.8/api/ruta/{id}
Las URL pueden ir variando conforme el desarrollador indique, y cada una puede hacer una acción diferentes. Para obtener más informacion puede consultar tutoriales sobre API en Laravel y entender los fundamentos de su funcionamiento.

En el siguiente video se muestra un apoyo para entender como ejecutar un proyecto de Laravel clonado desde GitHub: https://www.youtube.com/watch?v=EdZ0hQtrfEU

Material de apoyo para entender Laravel un poco (me ayudo mucho xd): https://youtube.com/playlist?list=PLAXHw-BiDq2qtHnYMHhEuOomdIg--mDRy
