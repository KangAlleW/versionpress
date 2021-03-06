/// <reference path='../common/Commits.d.ts' />
/// <reference path='../../interfaces/State.d.ts' />

import * as React from 'react';
import { inject, observer } from 'mobx-react';
import { RouteComponentProps } from 'react-router-dom';

import CommitPanel from '../commit-panel/CommitPanel';
import CommitsTable from '../commits-table/CommitsTable';
import Navigation from '../navigation/Navigation';
import ProgressBar from '../progress-bar/ProgressBar';
import ServicePanel from '../service-panel/ServicePanel';
import UpdateNotice from './update-notice/UpdateNotice';
import VpTitle from './vp-title/VpTitle';
import WelcomePanel from '../welcome-panel/WelcomePanel';

import { fetchCommits, fetchWelcomePanel, fetchSearchConfig, hideWelcomePanel } from '../../actions';
import { AppStore } from '../../stores/appStore';
import { LoadingStore } from '../../stores/loadingStore';

import './HomePage.less';

interface HomePageProps extends RouteComponentProps<{page?: string }> {
  appStore?: AppStore;
  loadingStore?: LoadingStore;
}

@inject('appStore', 'loadingStore')
@observer
export default class HomePage extends React.Component<HomePageProps, {}> {

  componentDidMount() {
    const { appStore, match } = this.props;

    if (match.params.page) {
      appStore!.setPage(match.params.page);
    }

    fetchWelcomePanel();
    fetchCommits();
    fetchSearchConfig();
  }

  componentWillReceiveProps(nextProps: HomePageProps) {
    const page = nextProps.match.params.page || 0;

    fetchCommits(page);
  }

  render() {
    const { appStore, loadingStore } = this.props;
    const {
      displayWelcomePanel,
      displayUpdateNotice,
      isDirtyWorkingDirectory,
    } = appStore!;
    const { progress } = loadingStore!;

    return (
      <div>
        <ProgressBar progress={progress} />
        <ServicePanel>
          <VpTitle />
        </ServicePanel>
        {isDirtyWorkingDirectory &&
          <CommitPanel />
        }
        {displayWelcomePanel &&
          <WelcomePanel onHide={hideWelcomePanel} />
        }
        {displayUpdateNotice &&
          <UpdateNotice onClick={fetchCommits} />
        }
        <Navigation />
        <CommitsTable />
      </div>
    );
  }

}
