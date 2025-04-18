<template>
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardHeader>
                    <strong>Editar tarea</strong>
                </CCardHeader>
                <CCardBody>
                    <div>
                        <div>
                            <h5>Datos de tarea</h5>
                            <hr>
                        </div>
                        <div class="row">
                            <div class="col-12 col-sm-6">
                                <div class="mb-3">
                                    <label class="form-label">Nombre</label>
                                    <input type="text" class="form-control" placeholder="Escribe aquí" v-model="nombre">
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">Estado de tarea</label>
                                <select class="form-control" v-model="estado">
                                    <option value="creada">Creada</option>
                                    <option value="progreso">En progreso</option>
                                    <option value="pausada">Pausada</option>
                                    <option value="finalizada">Finalizada</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5">
                        <div>
                            <h5>Operativa de tarea</h5>
                            <hr>
                        </div>
                        <div class="formBuilder">
                            <div class="row">

                                <div class="col-12 col-sm-3" v-for="item in templates">
                                    <div class="btn btn-primary">
                                        <i class="fa fa-tasks me-3"></i> {{item.nombre}}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 text-end">
                        <button @click="$router.push('/admin/formularios')" class="btn btn-danger me-4">Cancelar</button>
                        <button @click="guardar" class="btn btn-primary">Guardar</button>
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import toolbox from "@/toolbox";
import {config} from "/src/config";
import Select from "@/views/forms/Select.vue";
import login from "@/views/pages/Login.vue";
import Multiselect from '@vueform/multiselect'

export default {
    name: 'Tables',
    computed: {
        login() {
            return login
        }
    },
    components: {Multiselect},
    data() {
        return {
            id: 0,
            nombre: '',
            estado: '',
            activo: 1,

            templates: {},
        };
    },
    mounted() {
        this.id = (typeof this.$route.params.id !== 'undefined') ? parseInt(this.$route.params.id) : 0;
        this.urlApp = config.appUrl;
        //console.log(this.id);
        this.getData();
        this.getTemplates();
        //this.getArchivadores();
    },
    methods: {
        getData() {

            const self = this;
            if (self.id > 0) {
                /*toolbox.doAjax('GET', 'admin/formulario/load/' + self.id, {},
                    function (response) {
                        self.id = response.data.id;
                        self.nombre = response.data.nombre;
                        self.urlAmigable = response.data.urlAmigable;
                        self.activo = !!response.data.activo;

                        if (typeof response.data.seccion !== 'undefined') {
                            Object.keys(response.data.seccion).map(function (a) {
                                const section = {
                                    id: response.data.seccion[a].id,
                                    nombre: response.data.seccion[a].nombre,
                                    campos: [],
                                }
                                Object.keys(response.data.seccion[a].campos).map(function (b) {

                                    const campo = {
                                        id: response.data.seccion[a].campos[b].id,
                                        nombre: response.data.seccion[a].campos[b].nombre,
                                        archivadorDetalleId: response.data.seccion[a].campos[b].archivadorDetalleId,
                                        archivadorCampo: response.data.seccion[a].campos[b].archivador_detalle.archivador.nombre + ' / ' + response.data.seccion[a].campos[b].archivador_detalle.nombre,
                                    }
                                    section.campos.push(campo);
                                })

                                self.secciones.push(section);
                            })
                        }
                    },
                    function (responseRole) {
                        toolbox.alert(responseRole.msg, 'danger');
                    });*/
            }
        },
        getTemplates() {
            const self = this;
            toolbox.doAjax('GET', 'admin/tareas-templates/list', {},
                function (response) {
                    //self.items = response.data;
                    self.templates = response.data;
                },
                function (response) {
                    //toolbox.alert(response.msg, 'danger');
                })
        },
        guardar() {

            const self = this;
            this.ordenarSecciones();

            toolbox.doAjax('POST', 'admin/formulario/save', {
                    id: self.id,
                    nombre: self.nombre,
                    activo: self.activo,
                    urlAmigable: self.urlAmigable,
                    campos: self.secciones,
                },
                function (response) {
                    toolbox.alert(response.msg, 'success');
                    /*if (self.id === 0) {
                        self.id = response.data;
                    }*/
                    self.$router.push('/admin/formularios');
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        addSection() {
            const section = {
                nombre: '',
                campos: [],
            }
            this.secciones.push(section);
        },
        resetCampo() {
            this.campo = {
                id: 0,
                nombre: '',
                tipoCampo: '',
                mascara: '',
                longitudMin: 0,
                longitudMax: 0,
            };
        },
        saveCampo(seccionKey) {
            this.secciones[seccionKey].agregarCampo = false;

            // traigo el archivador desc
            const self = this;
            this.optionsArchivadores.forEach(function (a) {
                if (a.value === self.campo.archivadorDetalleId) {
                    self.campo.archivadorCampo = a.label;
                }
            });

            if (typeof this.secciones[seccionKey].campos === 'undefined') {
                this.secciones[seccionKey].campos = [];
            }

            if (parseInt(this.campo.id) === 0) {
                this.secciones[seccionKey].campos.push(this.campo);
            }
            this.resetCampo();
        },
        deleteCampo(key, item) {
            const self = this;
            toolbox.confirm('Si elimina un campo que esté asociado a información de formularios, este únicamente se desactivará. ¿Desea continuar?', function () {
                self.campos.splice(key, 1);

                toolbox.doAjax('POST', 'admin/formulario/delete-field', {
                        id: item.id,
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'success');
                    },
                    function (response) {
                        toolbox.alert(response.msg, 'danger');
                    })
            });
        },
        editCampo(item) {
            this.agregarCampo = true;
            this.campo = item;
        },
        changeTipoCampo(tipo) {
            this.campo.tipoCampo = tipo;
        },
        getArchivadores() {
            const self = this;
            toolbox.doAjax('GET', 'admin/archivador/fields', {},
                function (response) {
                    Object.keys(response.data).map(function (a, b) {
                        self.optionsArchivadores.push({
                            value: response.data[a].id,
                            label: response.data[a].nombre,
                        })
                    })
                },
                function (responseRole) {
                    toolbox.alert(responseRole.msg, 'danger');
                });
        },
        friendlyUrl() {
            this.urlAmigable = this.nombre.replace(/[^a-z0-9_]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();
        },
        ordenarSecciones() {
            const self = this;
            this.secciones.forEach(function (a, b) {
                self.secciones[b].orden = b;
            })
        },
        moverSeccionArriba(old_index) {
            const new_index = old_index-1;
            if (new_index >= this.secciones.length) {
                var k = new_index - this.secciones.length + 1;
                while (k--) {
                    this.secciones.push(undefined);
                }
            }
            this.secciones.splice(new_index, 0, this.secciones.splice(old_index, 1)[0]);
        },
        moverSeccionAbajo(old_index) {
            const new_index = old_index+1;
            if (new_index >= this.secciones.length) {
                var k = new_index - this.secciones.length + 1;
                while (k--) {
                    this.secciones.push(undefined);
                }
            }
            this.secciones.splice(new_index, 0, this.secciones.splice(old_index, 1)[0]);
        },
    }
}
</script>
