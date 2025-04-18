<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Editar reporte</strong>
                </CCardHeader>
                <CCardBody>
                    <h5>Datos generales</h5>
                    <hr>
                    <div class="row">
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Nombre</label>
                                <input type="text" class="form-control" placeholder="Escribe aquí" v-model="nombre">
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label for="password" class="form-label">Rol asignado</label>
                                <select class="form-select" v-model="user.rolUsuario">
                                    <option v-for="rol in roleList" :value="rol.name">{{ rol.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Producto</label>
                                <div>
                                    <multiselect
                                        v-model="flujos"
                                        :options="flujosOptions"
                                        :mode="'tags'"
                                        :searchable="true"
                                        @select="getCampos"
                                    />
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Estado</label>
                                <select class="form-control" v-model="activo">
                                    <option value="1">Activo</option>
                                    <option value="0">Desactivado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <h5 class="mt-4">Configuración</h5>
                    <hr>
                    <div>
                        <label>Campos a mostrar</label>
                        <div>
                            <multiselect
                                v-model="campos"
                                :options="camposOptions"
                                :mode="'tags'"
                                :searchable="true"/>
                        </div>
                    </div>
                    <div>
                        <div class="mt-4 text-end">
                            <button @click="$router.push('/usuarios/listado')" class="btn btn-danger me-4">Cancelar</button>
                            <button @click="guardar" class="btn btn-primary">Guardar</button>
                        </div>
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import Multiselect from '@vueform/multiselect'
import Select from "@/views/forms/Select.vue";


export default {
    name: 'Tables',
    components: {Select, Multiselect},
    data() {
        return {
            id: 0,
            user: {
                id: 0,
                log: {}
            },
            nombre: '',
            activo: 0,
            producto: 0,
            tmpCampos: {},

            campos: [],
            camposOptions: [],

            flujos: [],
            flujosOptions: [],
            roleList: {},
        };
    },
    mounted() {
        this.id = (typeof this.$route.params.id !== 'undefined') ? parseInt(this.$route.params.id) : 0;
        this.getFlujos();
        this.getRol();
    },
    methods: {
        getRol() {
            const self = this;

            toolbox.doAjax('GET', 'users/role/list', {},
                function (response) {

                    self.roleList = response.data;

                    if (self.id > 0) {

                        toolbox.doAjax('GET', 'users/load/user/' + self.id, {},
                            function (response) {
                                self.user.id = response.data.id;
                                self.user.rolUsuario = response.data.rolUsuario;
                                self.logItems = toolbox.prepareForTable(response.data.logs);

                                self.changePassword = false;

                            },
                            function (response) {
                                // toolbox.alert(response.msg, 'danger');
                            })
                    }
                },
                function (response) {
                    // toolbox.alert(response.msg, 'danger');
                });
        },
        getData() {
            const self = this;

            toolbox.doAjax('POST', 'reportes/get', {
                    id: self.id,
                },
                function (response) {
                    self.id = response.data.id;
                    self.activo = response.data.activo;
                    self.nombre = response.data.nombre;
                    self.user.rolUsuario = response.data.rolUsuario;

                    self.flujos = [];
                    Object.keys(response.data.c.p).map(function (a, b) {
                        self.flujos.push(response.data.c.p[a]);
                    })

                    self.tmpCampos = response.data.c.c;
                    self.getCampos();
            })
        },
        getFlujos() {
            const self = this;

            toolbox.doAjax('GET', 'reportes/get-flujos', {},
                function (response) {
                    self.flujosOptions = [];
                    Object.keys(response.data).map(function (a, b) {
                        self.flujosOptions.push({
                            value: response.data[a].id,
                            label: response.data[a].nombreProducto,
                        })
                    })

                    self.getData();
                    //self.getCampos();
            })
        },
        guardar() {

            const self = this;

            let errors = false;
            if (toolbox.isEmpty(this.nombre)) {
                toolbox.alert('Debe ingresar un nombre de usuario', 'danger');
                errors = true;
            }

            if (!errors) {
                toolbox.doAjax('POST', 'reportes/save', {
                        id: self.id,
                        nombre: self.nombre,
                        activo: self.activo,
                        flujos: self.flujos,
                        productos: self.productos,
                        campos: self.campos,
                        rolUsuario: self.user.rolUsuario,
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                        if (self.id === 0) {
                            self.id = response.data.id;
                            self.$router.push('/reportes/configuracion/' + response.data.id);
                        }
                        self.getData();
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            }
        },
        getCampos() {
            const self = this;
            console.log(this.flujos);
            toolbox.doAjax('POST', 'reportes/nodos/campos', {
                    productos: self.flujos
                },
                function (response) {

                    self.camposOptions = [];
                    Object.keys(response.data).map(function (a, b) {
                        self.camposOptions.push({
                            value: response.data[a].id,
                            label: response.data[a].pr + ' - ' +response.data[a].nodo + " - " + response.data[a].label,
                        })
                    })

                    self.campos = [];
                    Object.keys(self.tmpCampos).map(function (a, b) {
                        self.campos.push(self.tmpCampos[a].id);
                    })
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                });
        }
    }
}
</script>
