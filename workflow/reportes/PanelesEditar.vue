<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Editar panel</strong>
                </CCardHeader>
                <CCardBody>
                    <h5>Datos generales</h5>
                    <div class="text-muted">
                        Un panel es un conjunto de gráficos que permiten visualizar información personalizada, dichos paneles son personalizados por cliente.
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-12 col-sm-4">
                            <div class="mb-3">
                                <label class="form-label">Nombre</label>
                                <input type="text" class="form-control" placeholder="Escribe aquí" v-model="nombre">
                            </div>
                        </div>
                        <div class="col-12 col-sm-8">
                            <div class="mb-3">
                                <label class="form-label">Enlace de panel</label>
                                <input type="text" class="form-control" placeholder="Escribe aquí" v-model="enlace">
                            </div>
                        </div>
                    </div>
                    <h5 class="mt-4">Configuración de acceso</h5>
                    <hr>
                    <div>
                        <label>Usuarios con acceso</label>
                        <div class="col-12">
                            <multiselect
                                v-model="usuariosD"
                                :options="usuariosOptions"
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
            nombre: '',
            enlace: '',

            usuariosD: [],
            usuariosOptions: [],
        };
    },
    mounted() {
        this.id = (typeof this.$route.params.id !== 'undefined') ? parseInt(this.$route.params.id) : 0;
        this.getData();
        this.getUsers();
    },
    methods: {
        getData() {
            const self = this;

            toolbox.doAjax('POST', 'paneles/get', {
                    id: self.id,
                },
                function (response) {
                    self.id = response.data.id;
                    self.enlace = response.data.urlPanel;
                    self.nombre = response.data.nombre;

                    self.usuariosD = [];
                    Object.keys(response.data.users).map(function (a) {
                        self.usuariosD.push(response.data.users[a]);
                    })
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
                toolbox.doAjax('POST', 'paneles/save', {
                        id: self.id,
                        nombre: self.nombre,
                        enlace: self.enlace,
                        users: self.usuariosD,
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                        if (self.id === 0) {
                            self.id = response.data.id;
                            self.$router.push('/paneles/configuracion/' + response.data.id);
                        }
                        self.getData();
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            }
        },
        getUsers() {

            const self = this;
            toolbox.doAjax('GET', 'users/list', {},
                function (response) {
                    self.usuariosOptions = [];
                    Object.keys(response.data).map(function (a, b) {
                        self.usuariosOptions.push({
                            value: response.data[a].id,
                            label: response.data[a].name + " ("+response.data[a].email+")",
                        })
                    })
                    self.getJerarquia();
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                }, {noCloseLoading: self.loading})
        },
    }
}
</script>
