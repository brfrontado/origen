<template>

    <div class="row">
        <div class="col-sm-12">
            <div>
                <h4>Ver tareas por producto</h4>
            </div>
            <div class="containerCard">
                <div class="card" v-for="(producto, index) in productos">
                    <div class="face face1">
                        <div class="content">
                            <img :src="producto.extraData.producto.mainImage || ''">
                            <h3>{{ producto.nombreProducto }}</h3>
                        </div>
                    </div>
                    <div class="p-3">
                        <div class="content">
                            <div class="tile-progressbar">
                                <span data-fill="65.5%" style="width: 65.5%;"></span>
                            </div>

                        </div>
                        <div class="btn  btn-primary w-100 align-bottom" @click="$router.push('/admin/tareas/listado-clientes/'+producto.id)">Ver tareas</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script>
import toolbox from "@/toolbox";
import vueKanban from 'vue-kanban';
import MetroTile from 'vue-metro-tile';

export default {
    name: 'Tables',
    components:[vueKanban,MetroTile],
    data() {
        return {
            productos: [],
            colores: ['tile-primary','tile-purple','tile-cyan', 'tile-green', 'tile-aqua','tile-blue','tile-red','tile-primary','tile-pink'],
            stages: ['Pendientes', 'En progreso', 'Rechazadas', 'Aprobadas'],
            blocks: [
                {
                    id: 1,
                    status: 'Pendientes',
                    title: 'Test',
                },
            ],
            headers: [
                {text: "Usuario asignado", value: "nombre"},
                {text: "Flujo", value: "flujo"},
                {text: "Tiempo de atención", value: "nombre"},
                {text: "Operación", value: "operation",  width: 150},
            ],
            items: []
        };
    },
    mounted() {
        this.getItems();
    },
    methods: {
        getItems() {

            const self = this;
            toolbox.doAjax('GET', 'productos/publicos', {}, function (response) {

                if (response.status) {
                    self.productos = response.data;
                    // Filtrar los productos que tienen la propiedad nombreProducto definida
                    const productosConNombre = self.productos.filter((producto) => producto.nombreProducto !== null && producto.nombreProducto !== undefined);

                    // Obtener los nombres de los productos en un array
                    const nombresProductos = productosConNombre.map((producto) => producto.nombreProducto);

                    self.labelsP = nombresProductos;
                }
                else {
                    self.msg = response.msg;

                }
            }, function (response) {
                self.msg = response.msg;
            })
        }
    }
}
</script>
