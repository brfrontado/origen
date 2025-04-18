<template>
    <CCard class="mb-4">
        <CCardBody>
            <div class="row">
                <div class="col-12 col-sm-6">
                    <highcharts :options="conteoTareas" class="mb-4"></highcharts>
                </div>
                <div class="col-12 col-sm-6 pt-2">

                    <div>
                        <h5 class="fw-bold mb-4">Filtrado de búsqueda</h5>
                    </div>
                    <div class="row">
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Buscar por No.</label>
                            <input class="form-control" v-model="filterSearchId" v-on:keyup.enter="getItems(false)"/>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Buscar por campos habilitados</label>
                            <input class="form-control" v-model="filterSearch" v-on:keyup.enter="getItems(false)"/>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 col-sm-6">
                            <div>
                                <label class="form-label">Fecha Inicial</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaIni">
                        </div>
                        <div class="col-12 col-sm-6">
                            <div>
                                <label class="form-label">Fecha Final</label>
                            </div>
                            <input type="date" class="form-control" placeholder="Selecciona la fecha" v-model="fechaFin">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Filtro por flujo</label>
                            <select class="form-select" v-model="productoId">
                                <option :value="0">Todos los flujos</option>
                                <option v-for="item in productos" :value="item.id">{{ item.n }}</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Filtro por estado</label>
                            <select class="form-select" v-model="estadoFilter">
                                <option value="__all__">Todos los estados</option>
                                <option v-for="(item, value) in estados" :value="value">{{ item.n }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-5">
                        <div class="col-12 text-end">
                            <button @click="showTimeline = true" class="btn btn-primary me-4"><i class="fas fa-stream"></i></button>
                            <button @click="getItems(false)" class="btn btn-primary me-4">Realizar búsqueda</button>
                            <button @click="getItems(true)" class="btn btn-danger">Reiniciar filtros</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="globalModal" v-if="showTimeline">
                <div class="globalModalContainer p-5">
                    <div @click="showTimeline = false" class="globalModalClose mt-3"><i class="fas fa-times-circle"></i></div>
                    <div>
                        <h5>Línea del tiempo</h5>
                    </div>
                    <hr>
                    <div class="taskTimeline">
                        <div class="row">
                            <div class="col-12 col-sm-4" v-for="item in timelineData">
                                <h5 class="fw-bold">{{item.p}}</h5>
                                <div>
                                    Tareas totales: {{item.c.cg}}
                                </div>
                                <!--<div>
                                    Etapas: {{item.c.cn}}
                                </div>-->
                                <div class="rightbox">
                                    <div class="rb-container">
                                        <ul class="rb">
                                            <li class="rb-item" ng-repeat="itembx" v-for="(node, nodeKey) in item.t">
                                                <div class="timestamp">
                                                    Etapa:
                                                </div>
                                                <div class="item-title">
                                                    <div v-if="nodeKey === '_nostep'">
                                                        Sin etapa (inicio)
                                                    </div>
                                                    <div v-else>
                                                        {{node.nn}}
                                                    </div>
                                                    <div class="small text-muted">
                                                        Tareas: {{node.c}}
                                                    </div>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CCardBody>
    </CCard>
<!--    <CCard class="mb-4">
        <CCardHeader>
            <strong>Filtros de búsqueda</strong>
        </CCardHeader>
        <CCardBody>

        </CCardBody>
    </CCard>-->
    <CRow>
        <CCol :xs="12">
            <CCard class="mb-4">
                <CCardBody>
                    <div class="row taskListItemHeader">
                        <div class="col-12 col-sm-11">
                            <div class="row">
                                <div class="col-4">
                                    Flujo
                                </div>
                                <div class="col-2">
                                    Fecha
                                </div>
                                <div class="col-2 text-center">
                                    Creado por
                                </div>
                                <div class="col-2  text-center">
                                    Asignado
                                </div>
                                <div class="col-2 text-center">
                                    Estado
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-1">
                            &nbsp;
                        </div>
                    </div>
                    <div v-for="(cotizacion, expedienteId) in items" class="mb-3 taskListItem">
                        <div class="row">
                            <div class="col-12 col-sm-11">
                                <div class="row">
                                    <div class="col-12 col-sm-4">
                                        <h6 class="fw-bold">{{ cotizacion.nP }} -
                                            <span class="text-primary"> No. {{ cotizacion.id }}</span></h6>
                                    </div>
                                    <div class="col-12 col-sm-2">
                                        {{ cotizacion.dC }}
                                    </div>
                                    <div class="col-12 col-sm-2 text-center">
                                        <span :data-letters="cotizacion.uI" v-tooltip="cotizacion.u"></span>
                                    </div>
                                    <div class="col-12 col-sm-2 text-center">
                                        <span :data-letters="cotizacion.usrAsI" v-tooltip="cotizacion.usrAs">{{ cotizacion.usrAs }}</span>
                                    </div>
                                    <div class="col-12 col-sm-2 text-center">
                                        {{ cotizacion.estado }}
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="taskListItemRes">
                                            <div>
                                                <div class="taskListItemResTitle">Expira:</div>
                                                <div class="taskListItemResVal">{{ cotizacion.dE }}</div>
                                            </div>
                                            <div>
                                                <div class="taskListItemResTitle">Creado por:</div>
                                                <div class="taskListItemResVal">{{ cotizacion.u }}</div>
                                            </div>
                                            <div>
                                                <div class="taskListItemResTitle">Estado:</div>
                                                <div class="taskListItemResVal">{{ cotizacion.estado }}</div>
                                            </div>
                                            <div>
                                                <div class="taskListItemResTitle">Fecha de creación:</div>
                                                <div class="taskListItemResVal">{{ cotizacion.dC }}</div>
                                            </div>
                                            <div v-for="(valueRes, keyRes) in cotizacion.resumen">
                                                <div class="taskListItemResTitle">{{ keyRes }}:</div>
                                                <div class="taskListItemResVal">{{ valueRes }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-1 text-end">
                                <a :href="'/#/flow/'+cotizacion.pTk+'/'+cotizacion.token" class="btn btn-success btn-sm w-100 mb-1" target="_blank" v-tooltip="'Ver detalle'"><i class="fas fa-eye"></i></a>
                                <button @click="copyLink('/#/f/'+cotizacion.pTk+'/'+cotizacion.token)" class="btn btn-outline-secondary btn-sm w-100 mb-1" v-tooltip="'Copiar link público'">
                                    <i class="fas fa-globe-americas"></i>
                                </button>
<!--                                <button @click="copyLink('/#/flow/'+cotizacion.pTk+'/'+cotizacion.token)" class="btn btn-outline-secondary btn-sm w-100 mb-1" v-tooltip="'Copiar link privado'">
                                    <i class="fas fa-link"></i>
                                </button>-->
                            </div>
                        </div>
                    </div>
                    <div v-if="!items || (items && Object.keys(items).length === 0)" class="text-muted text-center">
                        Sin tareas a mostrar
                    </div>
                    <div v-if="items && Object.keys(items).length > 0" class="text-end">
                        <nav>
                            <ul class="pagination justify-content-end">
                                <li class="page-item" v-if="pageNumber > 0">
                                    <a class="page-link" @click="pageNumber--; getItems()">Anterior</a></li>
                                <li class="page-item" v-for="page in pagesNumeration">
                                    <a :class="{'page-link active': pageNumber === page, 'page-link': pageNumber !== page}" @click="pageNumber = page; getItems()">{{ page + 1 }}</a>
                                </li>
                                <li class="page-item" v-if="pageNumber + 1 < pages">
                                    <a class="page-link" @click="pageNumber++; getItems()">Siguiente</a></li>
                            </ul>
                        </nav>
                    </div>
                    <hr>
                    <div class="text-end text-muted">
                        Limitado a 50 tareas por página
                    </div>
                </CCardBody>
            </CCard>
        </CCol>
    </CRow>
</template>

<script>
import {config} from "@/config";
import toolbox from "@/toolbox";
import {useRoute} from 'vue-router';
import Button from "@/views/forms/form_elements/FormElementButton.vue";
import Select from "@/views/forms/Select.vue";
import dayjs from "dayjs";
import {Chart as highcharts} from "highcharts-vue";

export default {
    name: 'Tables',
    components: {
        highcharts,
        Select,
        Button,
        useRoute,
    },
    data() {
        return {
            fechaIni: dayjs().format('YYYY-MM-DD'),
            fechaFin: dayjs().format('YYYY-MM-DD'),
            items: {},
            estados: {},
            productos: {},
            productoId: 0,
            estadoFilter: '__all__',
            filterSearchId: '',
            filterSearch: '',

            // paginación
            pages: 0,
            pageNumber: 0,
            pagesNumeration: {},

            // línea del tiempo
            showTimeline: false,
            timelineData: {},

            // gráfico
            conteoTotal: 0,
            conteoTareas: {
                chart: {
                    type: 'bar'
                },
                title: {
                    text: 'En esta búsqueda',
                    align: 'left'
                },
                xAxis: {
                    categories: ['Flujos'],
                    title: {
                        text: null
                    },
                    gridLineWidth: 1,
                    lineWidth: 0
                },
                yAxis: {
                    min: 0,
                    title: {
                        text: 'Conteo de tareas',
                        align: 'high'
                    },
                    labels: {
                        overflow: 'justify'
                    },
                    gridLineWidth: 0
                },
                tooltip: {
                    valueSuffix: ' tareas'
                },
                plotOptions: {
                    bar: {
                        borderRadius: '10%',
                        dataLabels: {
                            enabled: true
                        },
                        groupPadding: 0.1
                    }
                },
                legend: {
                    layout: 'horizontal',
                    align: 'center',
                    verticalAlign: 'bottom',
                    floating: false,
                    borderWidth: 0,
                    shadow: false
                },
                credits: {
                    enabled: false
                },
                series: []
            },
        };
    },
    mounted() {
        this.getProducts();
        this.getItems();
        //this.productoIdSelected = (typeof useRoute().params.productoId !== 'undefined') ? parseInt(useRoute().params.productoId) : 0;
    },
    methods: {
        loadData() {
            const self = this;
            toolbox.doAjax('GET', 'productos/internos/' + this.productoIdSelected, {}, function (response) {

                // self.getProductos();

                if (response.status) {
                    // self.productos = (typeof response.data[0] !== 'undefined') ? response.data[0] : [];
                    self.productosToSend = response.data;
                    //self.filterInputNodes(false)
                } else {
                    self.msg = response.msg;

                }
            }, function (response) {
                self.msg = response.msg;
            })
        },
        getItems(limpiar) {

            if (!limpiar) limpiar = false;

            if (limpiar) {
                this.productoId = 0;
                this.filterSearchId = '';
                this.filterSearch = '';
                this.estadoFilter = '__all__';
                this.fechaIni = dayjs().format('YYYY-MM-DD');
                this.fechaFin = dayjs().format('YYYY-MM-DD');
                this.pageNumber = 0;
            }

            if (this.filterSearch !== '' && this.filterSearchId !== '')  {
                this.pageNumber = 0;
            }

            const self = this;
            toolbox.doAjax('POST', 'tareas/all', {
                    fechaIni: self.fechaIni,
                    fechaFin: self.fechaFin,
                    filterSearchId: self.filterSearchId,
                    filterSearch: self.filterSearch,
                    estadoFilter: self.estadoFilter,
                    productoId: self.productoId,
                    page: self.pageNumber,
                },
                function (response) {
                    // cálculo de páginas
                    self.pages = response.data.p;
                    self.pagesNumeration = response.data.pn;
                    self.items = response.data.c;
                    self.timelineData = response.data.tim;

                    self.conteoTotal = 0;
                    self.conteoTareas.series = [];
                    Object.keys(response.data.g).map(function (a) {
                        self.conteoTareas.series.push({
                            name: response.data.g[a].p,
                            data: [
                                response.data.g[a].c
                            ]
                        });
                        self.conteoTotal = self.conteoTotal + response.data.g[a].c;
                    })

                    self.conteoTareas.title.text = "En esta búsqueda, "+self.conteoTotal+" tareas";

                    setTimeout(function () {
                        window.scrollTo({top: 0, left: 0, behavior: "instant"})
                    }, 100)
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                })
        },
        getProducts() {

            const self = this;
            toolbox.doAjax('POST', 'tareas/prod/filter', {},
                function (response) {
                    self.productos = response.data;
                },
                function (response) {
                    toolbox.alert(response.msg, 'danger');
                },{}, false)
        },
        autoFiltroEstado(estado) {
            this.estadoFilter = estado;
            this.getItems(false);
        },
        copyLink(link) {
            const linkTmp = config.appUrl + link;
            toolbox.copyToClipboard(linkTmp);
        },
        copyIDT(token) {
            toolbox.copyToClipboard(token);
        },
    }
}
</script>
